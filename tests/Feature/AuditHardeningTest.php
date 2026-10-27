<?php

namespace Tests\Feature;

use App\Services\Billing\PaynowGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuditHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingress_blocks_before_session_start_even_for_missing_routes(): void
    {
        RateLimiter::for('ingress', fn () => Limit::perMinute(1)->by('audit-ingress'));
        $this->get('/does-not-exist')->assertNotFound();
        $this->get('/login')->assertTooManyRequests()->assertHeader('Retry-After')->assertHeaderMissing('Set-Cookie');
    }

    public function test_public_page_budget_cannot_be_bypassed_by_changing_pages_or_forwarded_ip(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->withHeader('X-Forwarded-For', '192.0.2.'.$i)->get('/login')->assertOk();
            if ($i % 10 === 0) {
                $this->travel(1)->seconds();
            }
        }
        $this->withHeader('X-Forwarded-For', '203.0.113.99')->get('/pricing')->assertTooManyRequests();
        $limits = RateLimiter::limiter('ingress')(request()->create('/pricing', 'GET'));
        $this->assertCount(3, $limits);
        $this->assertSame(60, $limits[2]->maxAttempts);
    }

    public function test_paynow_nested_unsigned_values_are_rejected_without_throwing(): void
    {
        config(['billing.paynow.integration_key' => 'audit-secret']);
        $this->assertFalse(app(PaynowGateway::class)->verify(['reference' => ['nested'], 'hash' => 'forged']));
    }

    public function test_valid_paynow_signature_cannot_be_replayed_against_another_payment(): void
    {
        config(['billing.paynow.integration_key' => 'audit-secret']);
        Http::preventStrayRequests();
        $data = ['reference' => 'original-reference', 'status' => 'Paid'];
        $data['hash'] = app(PaynowGateway::class)->signature($data);
        $this->call('POST', '/billing/paynow/notify/different-reference', [], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($data))->assertBadRequest();
        Http::assertNothingSent();
    }

    public function test_paynow_payload_limit_runs_before_database_lookup(): void
    {
        $this->call('POST', '/billing/paynow/notify/unknown', [], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], str_repeat('a', 65537))->assertStatus(413);
    }

    public function test_login_rejects_oversized_credentials_before_password_verification(): void
    {
        $this->postJson('/login', ['email' => 'person@example.com', 'password' => str_repeat('a', 1025)])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
