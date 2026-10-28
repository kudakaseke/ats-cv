<?php

namespace App\Jobs;

use App\Http\Controllers\CvCheckerController;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\CvAiRewriter;
use App\Services\CvKeywordMatcher;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessCvAi implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 360;
    public int $tries = 0;
    public bool $failOnTimeout = true;
    public int $expiresAt;

    public function __construct(public int $userId, public int $operationId, public string $feature,
        public array $input, public ?array $baseline, public string $model, public string $provider = 'openai')
    {
        $this->onConnection('ai')->onQueue('ai')->afterCommit();
        $this->expiresAt = now()->addMinutes(9)->timestamp;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return \Illuminate\Support\Carbon::createFromTimestamp($this->expiresAt);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('openai-cv'))->shared()->releaseAfter(5)->expireAfter(400)];
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user || ! DB::table('ai_operations')->where('id', $this->operationId)
            ->where('user_id', $user->id)->where('status', 'reserved')->exists()) {
            return;
        }
        if ($this->provider !== 'openai') {
            $this->failed(null);
            return;
        }
        // Do not start a paid request too late to finish before abandoned-credit recovery.
        if (time() > $this->expiresAt - 330) {
            $this->failed(null);
            return;
        }
        $previousRequest = app('request');
        $previousProvider = config('services.cv_ai.provider');
        $previousModel = config('services.'.$this->provider.'.model');
        $request = Request::create('/checker/'.$this->feature, 'POST', $this->input);
        $request->setUserResolver(fn () => $user);
        $session = new Store('ai-worker', new ArraySessionHandler(120));
        $session->start();
        $session->put('cv_checker.scan', $this->baseline);
        $request->setLaravelSession($session);
        $request->attributes->set('ai_background', true);
        $request->attributes->set('ai_reserved_operation', (object) ['id' => $this->operationId, 'cached' => false]);
        app()->instance('request', $request);
        // Laravel's request-rebinding hook resets this resolver; set it after binding.
        $request->setUserResolver(fn () => $user);
        config(['services.cv_ai.provider' => $this->provider, 'services.'.$this->provider.'.model' => $this->model]);
        if (function_exists('set_time_limit')) @set_time_limit(350);
        try {
            $controller = app(CvCheckerController::class);
            $services = [$request, app(CvKeywordMatcher::class), app(AiCreditService::class), app(CvAiRewriter::class)];
            if ($this->feature === 'analyse') {
                $controller->scan(...$services);
            } else {
                $controller->rewrite($request, $services[3], $services[1], $services[2]);
            }
        } catch (Throwable $exception) {
            // Never automatically repeat a model request whose billing/outcome is unknown.
            report($exception);
            $this->failed($exception);
        } finally {
            app()->instance('request', $previousRequest);
            config(['services.cv_ai.provider' => $previousProvider, 'services.'.$this->provider.'.model' => $previousModel]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($user = User::find($this->userId)) {
            app(AiCreditService::class)->finish($user, $this->operationId, null, ['provider' => $this->provider, 'model' => $this->model]);
        }
    }
}
