<?php

namespace Tests\Feature;

use App\Services\SentryIncidentReporter;
use Illuminate\Support\Facades\Route;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Serializer\PayloadSerializer;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

class SentryReportingTest extends TestCase
{
    private function transport(): object
    {
        config(['sentry.dsn' => 'https://synthetic@example.invalid/1']);
        $transport = new class implements TransportInterface
        {
            public array $events = [];

            public function send(Event $event): Result
            {
                $this->events[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
        app()->instance(ClientBuilder::class, app(ClientBuilder::class)->setTransport($transport));
        app()->forgetInstance(HubInterface::class);

        return $transport;
    }

    public function test_real_exception_pipeline_sends_one_sanitised_correlated_event(): void
    {
        $transport = $this->transport();
        Route::post('/_test/sentry', fn () => throw new \RuntimeException('PRIVATE_CV_API_KEY_JOB_DESCRIPTION'))->name('test.sentry');
        $response = $this->postJson('/_test/sentry', ['cv' => 'PRIVATE_REQUEST_BODY'], ['Authorization' => 'Bearer PRIVATE_AUTH'])->assertStatus(500);
        $this->assertCount(1, $transport->events);
        $event = $transport->events[0];
        $this->assertSame($response->json('reference'), $event->getTags()['error_reference']);
        $this->assertSame('test.sentry', $event->getTags()['route']);
        $this->assertSame('RuntimeException', $event->getExceptions()[0]->getType());
        $payload = (new PayloadSerializer(app(HubInterface::class)->getClient()->getOptions()))->serialize($event);
        foreach (['PRIVATE_CV', 'PRIVATE_REQUEST', 'PRIVATE_AUTH', base_path(), 'context_line', 'cookies', 'Authorization'] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
        $this->assertNotEmpty($event->getExceptions()[0]->getStacktrace()->getFrames());
        $this->assertSame([], $event->getRequest());
        $this->assertNull($event->getUser());
        $this->assertSame([], $event->getExtra());
        $this->assertSame([], $event->getBreadcrumbs());
    }

    public function test_raw_capture_is_rejected_and_does_not_bypass_scrubbing(): void
    {
        $transport = $this->transport();
        $this->assertNull(app(HubInterface::class)->captureException(new \RuntimeException('PRIVATE_SECRET')));
        $this->assertCount(0, $transport->events);
        $this->assertNull(SentryIncidentReporter::beforeSend(Event::createEvent()));
    }

    public function test_sentry_transport_failure_keeps_safe_user_response_and_local_reporting(): void
    {
        $hub = $this->mock(HubInterface::class);
        config(['sentry.dsn' => 'https://synthetic@example.invalid/1']);
        $hub->shouldReceive('captureEvent')->once()->andThrow(new \RuntimeException('PRIVATE_TRANSPORT_SECRET'));
        Route::get('/_test/sentry-outage', fn () => throw new \RuntimeException('PRIVATE_CV'));
        $this->getJson('/_test/sentry-outage')->assertStatus(500)->assertJsonStructure(['reference'])->assertDontSee('PRIVATE');
    }

    public function test_setup_command_uses_real_incident_pipeline_without_external_requests(): void
    {
        $transport = $this->transport();
        $this->artisan('app:sentry-test')->assertSuccessful();
        $this->assertCount(1, $transport->events);
        $this->assertSame('App\\Exceptions\\SentrySetupTest', $transport->events[0]->getExceptions()[0]->getType());
    }

    public function test_setup_command_requires_a_dsn(): void
    {
        config(['sentry.dsn' => null]);
        $this->artisan('app:sentry-test')->assertFailed();
    }
}
