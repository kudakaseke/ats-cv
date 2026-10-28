<?php

namespace Tests\Feature;

use App\Services\CvAiRewriter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GptSolProviderTest extends TestCase
{
    public function test_sol_uses_low_reasoning_and_the_existing_strict_output_contract(): void
    {
        Http::beforeSending(function ($request, $options) {
            $this->assertSame(120, $options['timeout']);
            $this->assertSame(10, $options['connect_timeout']);
        });
        $this->checkModel('gpt-6.1-sol');
        Http::assertSent(fn ($request) => $request['reasoning']['effort'] === 'low'
            && $request['max_output_tokens'] === 16000
            && $request['text']['format']['strict'] === true
            && $request['text']['verbosity'] === 'low'
            && $request['store'] === false
            && ! isset($request['temperature'])
            && ! isset($request['top_p']));
    }

    public function test_gpt_41_remains_usable_without_reasoning_parameters(): void
    {
        $this->checkModel('gpt-4.1');
        Http::assertSent(fn ($request) => ! isset($request['reasoning']));
    }

    public function test_usage_records_the_model_reported_by_openai(): void
    {
        config(['services.cv_ai.provider' => 'openai', 'services.openai.key' => 'fake-key', 'services.openai.model' => 'gpt-6.1-sol']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'model' => 'gpt-6.1-sol-test-snapshot',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text',
                'text' => json_encode(['summary' => 'Review complete.', 'recommendations' => []])]]]]])]);
        $service = new CvAiRewriter;
        $service->analyse('PHP developer', 'Alex');
        $this->assertSame('gpt-6.1-sol-test-snapshot', $service->lastUsage['model']);
    }

    public function test_analysis_budget_is_configurable_and_has_a_hard_upper_bound(): void
    {
        config(['services.openai.analysis_output_tokens' => 999999]);
        $this->checkModel('gpt-6.1-sol');
        Http::assertSent(fn ($request) => $request['max_output_tokens'] === 32000);
    }

    private function checkModel(string $model): void
    {
        config(['services.cv_ai.provider' => 'openai', 'services.openai.key' => 'fake-key', 'services.openai.model' => $model]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text',
                'text' => json_encode(['summary' => 'Review complete.', 'recommendations' => []])]]]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50]])]);
        $service = new CvAiRewriter;
        $this->assertSame('Review complete.', $service->analyse('PHP developer', 'Alex, PHP developer')['summary']);
        $this->assertSame($model, $service->lastUsage['model']);
        Http::assertSentCount(1);
    }
}
