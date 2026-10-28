<?php

namespace Tests\Feature;

use App\Exceptions\AiResponseException;
use App\Services\CvAiRewriter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KimiProviderTest extends TestCase
{
    private function configure(): void
    {
        \Illuminate\Support\Sleep::fake();
        config(['services.cv_ai.provider' => 'kimi', 'services.kimi.key' => 'test-key', 'services.kimi.model' => 'kimi-k3.1-pro']);
        Http::preventStrayRequests();
    }

    public function test_analysis_uses_kimi_and_ignores_reasoning_content(): void
    {
        $this->configure();
        Http::fake(['api.moonshot.ai/*' => Http::response([
            'choices' => [['finish_reason' => 'stop', 'message' => [
                'reasoning_content' => 'This is not JSON.',
                'content' => json_encode(['summary' => 'Review complete.', 'recommendations' => []]),
            ]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200],
        ])]);
        $service = new CvAiRewriter;
        $this->assertSame('Review complete.', $service->analyse('PHP developer', 'Alex, PHP developer')['summary']);
        $this->assertSame(['provider' => 'kimi', 'model' => 'kimi-k3.1-pro', 'input_tokens' => 100, 'output_tokens' => 200], $service->lastUsage);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.moonshot.ai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['model'] === 'kimi-k3.1-pro'
            && $request['response_format']['json_schema']['strict'] === true
            && $request['reasoning_effort'] === 'low'
            && ! isset($request['temperature']));
    }

    public function test_truncated_kimi_output_is_rejected(): void
    {
        $this->configure();
        Http::fake(['api.moonshot.ai/*' => Http::response(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{}']]]])]);
        $this->expectException(AiResponseException::class);
        (new CvAiRewriter)->analyse('PHP developer', 'Alex');
    }

    public function test_rewrite_uses_kimi_and_preserves_confirmed_skills(): void
    {
        $this->configure();
        $draft = ['structured_resume' => ['name' => 'Alex', 'role' => '', 'email' => '', 'phone' => '',
            'location' => '', 'website' => '', 'summary' => 'PHP developer.', 'references' => '',
            'skills' => ['PHP'], 'core_strengths' => [], 'competencies' => [], 'experience' => [],
            'education' => [], 'achievements' => [], 'courses' => [], 'languages' => [], 'custom_sections' => []],
            'changes' => [], 'unsupported_keywords' => []];
        Http::fake(['api.moonshot.ai/*' => Http::response(['choices' => [['finish_reason' => 'stop',
            'message' => ['content' => json_encode($draft)]]]])]);
        $result = (new CvAiRewriter)->rewrite('PHP HTML developer', 'Alex, PHP developer', ['HTML']);
        $this->assertContains('HTML', $result['structured_resume']['skills']);
        Http::assertSentCount(1);
    }

    public function test_provider_error_does_not_expose_response_body(): void
    {
        $this->configure();
        Http::fake(['api.moonshot.ai/*' => Http::response(['error' => ['message' => 'private data']], 429)]);
        try {
            (new CvAiRewriter)->analyse('PHP', 'Alex');
            $this->fail('Expected provider error.');
        } catch (AiResponseException $exception) {
            $this->assertStringNotContainsString('private data', $exception->getMessage());
            $this->assertSame('rate_limit', $exception->failureReason);
        }
        Http::assertSentCount(2);
    }

    public function test_temporary_rate_limit_is_retried_without_switching_to_gpt(): void
    {
        $this->configure();
        $waits = [];
        \Illuminate\Support\Sleep::whenFakingSleep(function ($duration) use (&$waits) { $waits[] = $duration->totalSeconds; });
        Http::fake(['api.moonshot.ai/*' => Http::sequence()
            ->push(['error' => ['type' => 'rate_limit_reached_error']], 429, ['Retry-After' => '1'])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode([
                'summary' => 'Review complete.', 'recommendations' => [],
            ])]]]], 200)]);
        $service = new CvAiRewriter;
        $this->assertSame('Review complete.', $service->analyse('PHP', 'Alex')['summary']);
        $this->assertSame('kimi', $service->lastUsage['provider']);
        Http::assertSentCount(2);
        $this->assertGreaterThanOrEqual(61, max($waits));
    }

    public function test_separate_requests_share_the_three_rpm_pacing_gate(): void
    {
        $this->configure();
        Http::fake(['api.moonshot.ai/*' => Http::response(['choices' => [['finish_reason' => 'stop',
            'message' => ['content' => json_encode(['summary' => 'Done.', 'recommendations' => []])]]]])]);
        (new CvAiRewriter)->analyse('PHP', 'Alex');
        (new CvAiRewriter)->analyse('PHP', 'Sam');
        \Illuminate\Support\Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds >= 20000);
        Http::assertSentCount(2);
    }

    public function test_concurrency_rejection_keeps_only_a_safe_failure_category(): void
    {
        $this->configure();
        Http::fake(['api.moonshot.ai/*' => Http::response(['error' => ['type' => 'rate_limit_reached_error',
            'message' => 'Private account details: reached max organization concurrency: 1']], 429)]);
        try {
            (new CvAiRewriter)->analyse('PHP', 'Alex');
            $this->fail('Expected rate limit.');
        } catch (AiResponseException $exception) {
            $this->assertSame('rate_limit_concurrency', $exception->failureReason);
            $this->assertStringNotContainsString('Private account', $exception->getMessage());
        }
    }

    public function test_invalid_credentials_are_not_retried(): void
    {
        $this->configure();
        Http::fake(['api.moonshot.ai/*' => Http::response([], 401)]);
        try {
            (new CvAiRewriter)->analyse('PHP', 'Alex');
            $this->fail('Expected provider error.');
        } catch (AiResponseException $exception) {
            $this->assertSame(401, $exception->providerStatus);
        }
        Http::assertSentCount(1);
    }

    public function test_missing_key_does_not_send_a_request(): void
    {
        $this->configure();
        config(['services.kimi.key' => null]);
        Http::fake();
        try {
            (new CvAiRewriter)->analyse('PHP', 'Alex');
            $this->fail('Expected configuration error.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('AI is not configured.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }
}
