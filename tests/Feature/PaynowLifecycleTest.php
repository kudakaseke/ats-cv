<?php

namespace Tests\Feature;

use App\Jobs\ProcessPaynowNotification;
use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\PaynowGateway;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaynowLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        config(['billing.paynow.enabled' => true, 'billing.paynow.mode' => 'live',
            'billing.paynow.live_currency_confirmed' => true,
            'billing.paynow.integration_id' => '123', 'billing.paynow.integration_key' => 'secret',
            'billing.paynow.callback_url' => null, 'performance.queue_payment_checks' => false,
            'performance.background_connection' => 'sync']);
        Http::preventStrayRequests();
    }

    private function payment(string $slug = 'monthly', ?User $user = null): BillingPayment
    {
        $plan = SubscriptionPlan::where('slug', $slug)->firstOrFail();

        return BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => ($user ?? User::factory()->create())->id,
            'subscription_plan_id' => $plan->id, 'provider' => 'paynow', 'mode' => 'live', 'currency' => 'USD',
            'amount_cents' => $plan->price_cents, 'billing_interval' => $plan->billing_interval,
            'credits' => config('ai_credits.plans.'.$slug), 'poll_url' => 'https://www.paynow.co.zw/interface/checkpayment?guid=test']);
    }

    public function test_local_checkout_returns_to_stable_app_url_when_quick_tunnel_is_used(): void
    {
        config(['app.env' => 'local', 'app.url' => 'http://localhost:8000',
            'billing.paynow.callback_url' => 'https://temporary-tunnel.example', 'billing.paynow.return_url' => null]);
        Http::fake(['*' => Http::response($this->signed(['status' => 'Ok',
            'browserurl' => 'https://www.paynow.co.zw/Payment/Confirm',
            'pollurl' => 'https://www.paynow.co.zw/interface/checkpayment?guid=test']))]);
        $this->actingAs(User::factory()->create())
            ->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertRedirect('https://www.paynow.co.zw/Payment/Confirm');

        Http::assertSent(function ($request) {
            parse_str($request->body(), $data);
            $reference = BillingPayment::sole()->reference;

            return $data['returnurl'] === 'http://localhost:8000/billing/paynow/return/'.$reference
                && $data['resulturl'] === 'https://temporary-tunnel.example/billing/paynow/notify/'.$reference;
        });
    }

    // Independent implementation: never use the production signer to create test responses.
    public function test_verification_does_not_hold_a_database_transaction_during_network_io(): void
    {
        $payment = $this->payment();
        $baseline = DB::transactionLevel();
        Http::fake(function () use ($payment, $baseline) {
            $this->assertSame($baseline, DB::transactionLevel());

            return Http::response($this->signed($this->payload($payment)));
        });
        app(PaymentService::class)->refresh($payment);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_simultaneous_verification_is_retryable_without_an_extra_provider_request(): void
    {
        $payment = $this->payment();
        $lock = Cache::lock('paynow-verification:'.$payment->id, 90);
        $this->assertTrue($lock->get());
        try {
            $this->notify($payment, $this->payload($payment))->assertStatus(503);
            Http::assertNothingSent();
            $this->assertNull(DB::table('payment_notifications')->sole()->processed_at);
        } finally {
            $lock->release();
        }
        Http::fake(['*' => Http::response($this->signed($this->payload($payment)))]);
        $this->notify($payment, $this->payload($payment))->assertOk();
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_callback_reencoding_does_not_create_another_notification(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::response($this->signed($this->payload($payment)))]);
        $this->notify($payment, $this->payload($payment))->assertOk();
        $reencoded = str_replace('-', '%2D', $this->signed($this->payload($payment)));
        $this->call('POST', '/billing/paynow/notify/'.$payment->reference, [], [], [], [], $reencoded)->assertOk();
        $this->assertDatabaseCount('payment_notifications', 1);
        Http::assertSentCount(1);
    }

    public function test_oversized_callback_is_rejected_without_storage_or_provider_access(): void
    {
        $payment = $this->payment();
        $this->call('POST', '/billing/paynow/notify/'.$payment->reference, [], [], [], [], str_repeat('x', 16385))->assertStatus(413);
        $this->assertDatabaseCount('payment_notifications', 0);
        Http::assertNothingSent();
    }

    public function test_paid_cannot_regress_and_reversed_cannot_be_reactivated(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::sequence()
            ->push($this->signed($this->payload($payment)))
            ->push($this->signed($this->payload($payment, 'Sent')))
            ->push($this->signed($this->payload($payment, 'Refunded')))
            ->push($this->signed($this->payload($payment)))]);
        $service = app(PaymentService::class);
        $service->refresh($payment);
        $service->refresh($payment);
        $this->assertSame('paid', $payment->fresh()->status);
        $service->refresh($payment);
        $service->refresh($payment);
        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertSame('cancelled', Subscription::sole()->status);
        $this->assertSame(0, app(AiCreditService::class)->wallet(User::findOrFail($payment->user_id))['subscription_credits']);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_polling_is_restricted_to_the_provider_payment_endpoint(): void
    {
        $payment = $this->payment();
        $payment->update(['poll_url' => 'https://www.paynow.co.zw/interface/initiatetransaction']);
        app(PaymentService::class)->refresh($payment);
        Http::assertNothingSent();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_checkout_limits_are_per_user_and_return_retry_headers(): void
    {
        Http::fake(['*' => Http::response($this->signed(['status' => 'Ok',
            'browserurl' => 'https://www.paynow.co.zw/Payment/Confirm',
            'pollurl' => 'https://www.paynow.co.zw/interface/checkpayment?guid=test']))]);
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 6; $i++) {
            $this->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertRedirect();
        }
        $this->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertStatus(429)->assertHeader('Retry-After');
        $this->actingAs(User::factory()->create())->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertRedirect();
        Http::assertSentCount(2);
    }

    public function test_first_subscription_honors_checkout_credit_snapshot_after_catalogue_changes(): void
    {
        $payment = $this->payment();
        config(['ai_credits.plans.monthly' => 100]);
        Http::fake(['*' => Http::response($this->signed($this->payload($payment)))]);
        app(PaymentService::class)->refresh($payment);
        $this->assertSame(150, app(AiCreditService::class)->wallet(User::findOrFail($payment->user_id))['credits']);
    }

    public function test_callback_limits_isolate_transactions_on_a_shared_provider_ip(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->call('POST', '/billing/paynow/notify/limited', [], [], [], [], 'hash=forged')->assertBadRequest();
        }
        $this->call('POST', '/billing/paynow/notify/limited', [], [], [], [], 'hash=forged')->assertStatus(429)->assertHeader('Retry-After');
        $this->call('POST', '/billing/paynow/notify/another-payment', [], [], [], [], 'hash=forged')->assertBadRequest();
        $this->assertDatabaseCount('payment_notifications', 0);
        Http::assertNothingSent();
    }

    public function test_callback_does_not_create_browser_sessions(): void
    {
        config(['session.driver' => 'database']);
        $this->call('POST', '/billing/paynow/notify/forged', [], [], [], [], 'hash=forged')->assertBadRequest();
        $this->assertDatabaseCount('sessions', 0);
        $this->assertDatabaseCount('payment_notifications', 0);
    }

    private function signed(array $data): string
    {
        $data['hash'] = strtoupper(hash('sha512', implode('', $data).config('billing.paynow.integration_key')));

        return http_build_query($data);
    }

    private function payload(BillingPayment $payment, string $status = 'Paid', array $overrides = []): array
    {
        return array_replace(['reference' => $payment->reference, 'amount' => number_format($payment->amount_cents / 100, 2, '.', ''),
            'paynowreference' => 'PN'.$payment->id, 'status' => $status], $overrides);
    }

    private function notify(BillingPayment $payment, array $data)
    {
        return $this->call('POST', '/billing/paynow/notify/'.$payment->reference, [], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $this->signed($data));
    }

    public function test_hash_matches_official_paynow_example(): void
    {
        config(['billing.paynow.integration_key' => '3e9fed89-60e1-4ce5-ab6e-6b1eb2d4f977']);
        $this->assertSame('2A033FC38798D913D42ECB786B9B19645ADEDBDE788862032F1BD82CF3B92DEF84F316385D5B40DBB35F1A4FD7D5BFE73835174136463CDD48C9366B0749C689',
            app(PaynowGateway::class)->signature(['id' => '1201', 'reference' => 'TEST REF', 'amount' => '99.99',
                'additionalinfo' => 'A test ticket transaction', 'returnurl' => 'http://www.google.com/search?q=returnurl',
                'resulturl' => 'http://www.google.com/search?q=resulturl', 'status' => 'Message']));
    }

    public static function statuses(): array
    {
        return [['Created', 'pending', 0], ['Sent', 'pending', 0], ['Paid', 'paid', 1],
            ['Awaiting Delivery', 'paid', 1], ['Delivered', 'paid', 1], ['Cancelled', 'cancelled', 0],
            ['Disputed', 'reversed', 0], ['Refunded', 'reversed', 0], ['Unexpected', 'pending', 0]];
    }

    #[DataProvider('statuses')]
    public function test_provider_status_policy(string $providerStatus, string $status, int $count): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::response($this->signed($this->payload($payment, $providerStatus)))]);
        app(PaymentService::class)->refresh($payment);
        $this->assertSame($status, $payment->fresh()->status);
        $this->assertSame($providerStatus, $payment->fresh()->provider_status);
        $this->assertDatabaseCount('subscriptions', $count);
        $this->assertDatabaseCount('billing_receipts', $count);
    }

    public function test_duplicate_notifications_and_returns_allocate_once_and_create_one_receipt(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::response($this->signed($this->payload($payment)))]);
        $this->notify($payment, $this->payload($payment))->assertOk();
        $this->notify($payment, $this->payload($payment))->assertOk();
        $this->actingAs(User::findOrFail($payment->user_id))->get('/payments/'.$payment->reference)->assertRedirect(route('dashboard'));
        $this->get('/payments/'.$payment->reference)->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billing_receipts', 1);
        $this->assertDatabaseCount('payment_notifications', 1);
        $this->assertSame(1, DB::table('ai_credit_transactions')->where('type', 'subscription_allocation')->count());
        $this->assertSame(150, app(AiCreditService::class)->wallet(User::findOrFail($payment->user_id))['credits']);
        $this->assertDatabaseCount('billing_messages', 2);
    }

    public function test_browser_return_before_notification_and_missed_notification_are_reconciled(): void
    {
        $payment = $this->payment('pro');
        Http::fake(['*' => Http::response($this->signed($this->payload($payment)))]);
        $this->actingAs(User::findOrFail($payment->user_id))->get('/payments/'.$payment->reference)->assertRedirect(route('dashboard'));
        $this->notify($payment, $this->payload($payment))->assertOk();
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertSame(225, app(AiCreditService::class)->wallet(User::findOrFail($payment->user_id))['credits']);
        $other = $this->payment('annual');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->signed($this->payload($other)))]);
        // Target the unresolved payment without rechecking the already verified one.
        $payment->refresh();
        $payment->update(['next_check_at' => now()->addDay()]);
        $this->artisan('billing:reconcile')->assertSuccessful();
        $this->assertSame('paid', $other->fresh()->status);
        $this->assertSame(2400, app(AiCreditService::class)->wallet(User::findOrFail($other->user_id))['credits']);
    }

    public static function mismatches(): array
    {
        return [[['amount' => '0.01']], [['reference' => 'wrong']], [['currency' => 'ZAR']], [['paynowreference' => '']], [['amount' => '4.750']]];
    }

    #[DataProvider('mismatches')]
    public function test_mismatched_poll_cannot_fulfill(array $overrides): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::response($this->signed($this->payload($payment, overrides: $overrides)))]);
        $this->actingAs(User::findOrFail($payment->user_id))->postJson('/payments/'.$payment->reference.'/check')->assertStatus(503);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_receipts', 0);
    }

    public function test_invalid_hash_or_wrong_notification_amount_never_polls(): void
    {
        $payment = $this->payment();
        $this->call('POST', '/billing/paynow/notify/'.$payment->reference, [], [], [], [], 'reference=x&hash=forged')->assertBadRequest();
        $this->notify($payment, $this->payload($payment, overrides: ['amount' => '0.01']))->assertBadRequest();
        Http::assertNothingSent();
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('payment_notifications', 0);
    }

    public function test_notification_failure_is_retryable_and_does_not_mark_it_processed(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::sequence()->push('status=Paid&hash=invalid')->push($this->signed($this->payload($payment)))]);
        $this->notify($payment, $this->payload($payment))->assertStatus(503);
        $this->assertNull(DB::table('payment_notifications')->sole()->processed_at);
        $this->notify($payment, $this->payload($payment))->assertOk();
        $this->assertNotNull(DB::table('payment_notifications')->sole()->processed_at);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_timeout_stays_pending_and_does_not_remove_existing_access(): void
    {
        $payment = $this->payment();
        $subscription = User::findOrFail($payment->user_id)->subscriptions()->create(['subscription_plan_id' => $payment->subscription_plan_id,
            'status' => 'active', 'starts_at' => now()->subDays(5), 'ends_at' => now()->addDays(20)]);
        Http::fake(fn () => throw new ConnectionException('Timeout'));
        $this->actingAs(User::findOrFail($payment->user_id))->postJson('/payments/'.$payment->reference.'/check')->assertStatus(503);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public static function unsafeUrls(): array
    {
        return [['http://www.paynow.co.zw/test'], ['https://paynow.co.zw.evil.test/test'], ['https://127.0.0.1/test'],
            ['https://user:password@www.paynow.co.zw/test'], ['https://www.paynow.co.zw:444/test'], ['https://www.paynow.co.zw/test#fragment']];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_poll_url_never_contacts_network(string $url): void
    {
        $payment = $this->payment();
        $payment->update(['poll_url' => $url]);
        app(PaymentService::class)->refresh($payment);
        Http::assertNothingSent();
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_duplicate_checkout_resumes_existing_attempt_instead_of_initializing_again(): void
    {
        $payment = $this->payment();
        $this->actingAs(User::findOrFail($payment->user_id))->post('/billing/paynow/monthly', ['billing_consent' => 1])
            ->assertRedirect(route('billing.show', $payment->reference));
        Http::assertNothingSent();
        $this->assertDatabaseCount('billing_payments', 1);
    }

    public static function renewalPlans(): array
    {
        return [['monthly', 150], ['annual', 2400]];
    }

    #[DataProvider('renewalPlans')]
    public function test_early_renewal_extends_paid_time_and_adds_credits_immediately_once(string $slug, int $credits): void
    {
        $payment = $this->payment($slug);
        $user = User::findOrFail($payment->user_id);
        $end = now()->addDays(10)->startOfSecond();
        $user->subscriptions()->create(['subscription_plan_id' => $payment->subscription_plan_id, 'status' => 'active',
            'starts_at' => now()->subDays(10), 'ends_at' => $end]);
        app(AiCreditService::class)->wallet($user);
        Http::fake(['*' => Http::response($this->signed($this->payload($payment)))]);
        app(PaymentService::class)->refresh($payment);
        $renewal = Subscription::findOrFail($payment->fresh()->subscription_id);
        $this->assertTrue($renewal->starts_at->equalTo($end));
        $expected = $slug === 'annual' ? $end->copy()->addYearNoOverflow() : $end->copy()->addMonthNoOverflow();
        $this->assertTrue($renewal->ends_at->equalTo($expected));
        $this->assertSame(1, DB::table('ai_credit_transactions')->where('type', 'subscription_allocation')->count());
        $this->assertSame($credits * 2, app(AiCreditService::class)->wallet($user)['credits']);
        $this->travelTo($end->copy()->addSecond());
        $this->assertSame($credits * 2, app(AiCreditService::class)->wallet($user)['credits']);
        $this->assertSame(1, DB::table('ai_credit_transactions')->where('type', 'subscription_allocation')->count());
        $this->assertSame(1, DB::table('ai_credit_transactions')->where('type', 'subscription_upfront')->count());
    }

    public function test_refund_or_dispute_revokes_only_funded_subscription_and_never_regrants(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::sequence()->push($this->signed($this->payload($payment)))
            ->push($this->signed($this->payload($payment, 'Disputed')))->push($this->signed($this->payload($payment)))]);
        $service = app(PaymentService::class);
        $service->refresh($payment);
        $service->refresh($payment->fresh());
        $service->refresh($payment->fresh());
        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertSame(0, app(AiCreditService::class)->wallet(User::findOrFail($payment->user_id))['credits']);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_receipt_admin_and_cancellation_require_correct_authorization(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::response($this->signed($this->payload($payment)))]);
        app(PaymentService::class)->refresh($payment);
        $other = User::factory()->create();
        $this->actingAs($other)->get('/payments/'.$payment->reference.'/receipt')->assertNotFound();
        $this->get('/admin/billing')->assertForbidden();
        $this->post('/admin/billing/'.$payment->reference.'/reconcile')->assertForbidden();
        $this->post('/admin/billing/'.$payment->reference.'/review')->assertForbidden();
        $this->post('/subscriptions/'.$payment->fresh()->subscription_id.'/cancel-renewal')->assertNotFound();
        $this->actingAs(User::findOrFail($payment->user_id))->get('/payments/'.$payment->reference.'/receipt')
            ->assertOk()->assertSee('ATS-'.$payment->id)->assertSee($payment->reference)->assertSee('not a tax invoice');
        $other->forceFill(['is_admin' => true])->save();
        $this->actingAs($other)->get('/admin/billing')->assertOk()->assertSee($payment->reference);
        $this->get('/admin/billing?export=csv')->assertDownload('paynow-payments.csv');
    }

    public function test_expiration_reminders_cancellation_and_email_outbox_are_idempotent(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::response($this->signed($this->payload($payment)))]);
        app(PaymentService::class)->refresh($payment);
        $subscription = Subscription::findOrFail($payment->fresh()->subscription_id);
        $this->actingAs(User::findOrFail($payment->user_id))->post('/subscriptions/'.$subscription->id.'/cancel-renewal')->assertRedirect();
        $this->post('/subscriptions/'.$subscription->id.'/cancel-renewal')->assertRedirect();
        $this->assertSame('active', $subscription->fresh()->status);
        $this->travelTo($subscription->ends_at->copy()->addSecond());
        Mail::fake();
        $this->artisan('billing:maintain')->assertSuccessful();
        $this->artisan('billing:maintain')->assertSuccessful();
        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertSame(1, DB::table('billing_messages')->where('event_key', 'subscription:'.$subscription->id.':expired')->count());
        $this->assertSame(0, DB::table('billing_messages')->whereNull('sent_at')->count());
    }

    public function test_signed_notification_recovers_poll_url_after_initialization_timeout(): void
    {
        $payment = $this->payment();
        $payment->update(['poll_url' => null]);
        $payload = $this->payload($payment, overrides: ['pollurl' => 'https://www.paynow.co.zw/interface/checkpayment?guid=recovered']);
        Http::fake(['*' => Http::response($this->signed($payload))]);
        $this->notify($payment, $payload)->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        Http::assertSent(fn ($request) => $request->url() === $payload['pollurl']);
    }

    public function test_callback_waits_for_poll_url_and_retries_safely(): void
    {
        $payment = $this->payment();
        $payment->update(['poll_url' => null]);
        $this->notify($payment, $this->payload($payment))->assertStatus(503);
        $this->assertNull(DB::table('payment_notifications')->sole()->processed_at);
        Http::assertNothingSent();
    }

    public function test_signed_initialization_error_allows_retry_without_fulfilling(): void
    {
        Http::fake(['*' => Http::response($this->signed(['status' => 'Error', 'error' => 'Invalid integration credentials']))]);
        $this->actingAs(User::factory()->create())->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertSessionHasErrors('billing');
        $this->assertSame('failed', BillingPayment::sole()->status);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_initialization_timeout_does_not_create_another_attempt_on_retry(): void
    {
        Http::fake(fn () => throw new ConnectionException('Timeout'));
        $this->actingAs(User::factory()->create())->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertSessionHasErrors('billing');
        $payment = BillingPayment::sole();
        $this->assertSame('pending', $payment->status);
        $this->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertRedirect(route('billing.show', $payment->reference));
        $this->assertDatabaseCount('billing_payments', 1);
    }

    public function test_untrusted_initialization_response_cannot_redirect_to_checkout(): void
    {
        Http::fake(['*' => Http::response('status=Ok&browserurl=https://evil.test&pollurl=https://www.paynow.co.zw/test&hash=forged')]);
        $this->actingAs(User::factory()->create())->post('/billing/paynow/pro', ['billing_consent' => 1])
            ->assertSessionHasErrors('billing');
        $this->assertNull(BillingPayment::sole()->checkout_url);
    }

    public function test_unknown_transaction_and_wrong_provider_reference_are_rejected(): void
    {
        $payment = $this->payment();
        $data = $this->payload($payment, overrides: ['reference' => 'unknown']);
        $this->call('POST', '/billing/paynow/notify/unknown', [], [], [], [], $this->signed($data))->assertNotFound();
        $payment->update(['provider_reference' => 'original']);
        $this->notify($payment, $this->payload($payment))->assertBadRequest();
        Http::assertNothingSent();
    }

    public function test_mail_failure_cannot_undo_payment_and_outbox_retries(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::response($this->signed($this->payload($payment)))]);
        app(PaymentService::class)->refresh($payment);
        Mail::shouldReceive('raw')->twice()->andThrow(new \RuntimeException('Mail unavailable'));
        $this->artisan('billing:maintain')->assertSuccessful();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(2, DB::table('billing_messages')->whereNull('sent_at')->count());
        $this->assertSame(2, DB::table('billing_messages')->where('attempts', 1)->count());
    }

    public function test_admin_refund_review_never_invents_a_refund_or_changes_verified_payment(): void
    {
        $payment = $this->payment();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->post('/admin/billing/'.$payment->reference.'/review',
            ['type' => 'refund_recorded', 'notes' => 'Refund requested through merchant portal.'])->assertRedirect();
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('billing_reviews', 1);
        Http::assertNothingSent();
    }

    public function test_plan_switching_and_additional_prepaid_periods_do_not_overlap(): void
    {
        config(['billing.paynow.plan_change_policy' => 'after_expiry']);
        $payment = $this->payment();
        $user = User::findOrFail($payment->user_id);
        $payment->update(['status' => 'cancelled']);
        $user->subscriptions()->create(['subscription_plan_id' => $payment->subscription_plan_id, 'status' => 'active',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(20)]);
        $this->actingAs($user)->post('/billing/paynow/pro', ['billing_consent' => 1])->assertSessionHasErrors('billing');
        $user->subscriptions()->create(['subscription_plan_id' => $payment->subscription_plan_id, 'status' => 'active',
            'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(50)]);
        $this->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertSessionHasErrors('billing');
        Http::assertNothingSent();
        $this->assertDatabaseCount('billing_payments', 1);
    }

    public function test_paid_plan_change_preserves_current_paid_time_and_adds_new_credits_now(): void
    {
        config(['billing.paynow.plan_change_policy' => 'end_of_cycle']);
        $payment = $this->payment('pro');
        $user = User::findOrFail($payment->user_id);
        $end = now()->addDays(12)->startOfSecond();
        $user->subscriptions()->create(['subscription_plan_id' => SubscriptionPlan::where('slug', 'annual')->value('id'),
            'status' => 'active', 'starts_at' => now()->subDays(20), 'ends_at' => $end]);
        Http::fake(['*' => Http::response($this->signed($this->payload($payment)))]);
        app(PaymentService::class)->refresh($payment);
        $this->assertTrue(Subscription::findOrFail($payment->fresh()->subscription_id)->starts_at->equalTo($end));
        $this->assertSame(2625, app(AiCreditService::class)->wallet($user)['credits']);
        $this->travelTo($end->copy()->addSecond());
        $this->assertSame(2625, app(AiCreditService::class)->wallet($user)['credits']);
    }

    public function test_unconfirmed_live_currency_cannot_contact_paynow(): void
    {
        config(['billing.paynow.live_currency_confirmed' => false]);
        $this->actingAs(User::factory()->create())->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertSessionHasErrors('billing');
        Http::assertNothingSent();
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_queued_callback_acknowledges_before_polling_and_worker_fulfills_once(): void
    {
        Queue::fake();
        config(['performance.queue_payment_checks' => true, 'performance.background_connection' => 'database']);
        $payment = $this->payment();
        $body = $this->signed($this->payload($payment));
        $this->call('POST', '/billing/paynow/notify/'.$payment->reference, [], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)->assertOk();
        Http::assertNothingSent();
        $this->assertDatabaseCount('subscriptions', 0);
        Queue::assertPushed(ProcessPaynowNotification::class);
        $job = new ProcessPaynowNotification(DB::table('payment_notifications')->sole()->fingerprint);
        Http::fake(['*' => Http::response($this->signed($this->payload($payment)))]);
        $job->handle(app(PaymentService::class));
        $job->handle(app(PaymentService::class));
        Http::assertSentCount(1);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billing_receipts', 1);
    }

    public function test_merchant_trace_recovers_lost_initialization_then_polls_before_fulfilling(): void
    {
        $payment = $this->payment();
        $payment->update(['poll_url' => null, 'merchant_trace' => str_repeat('a', 32)]);
        $data = $this->payload($payment, overrides: ['pollurl' => 'https://www.paynow.co.zw/interface/checkpayment?guid=recovered']);
        Http::fake([
            '*/interface/trace' => Http::response($this->signed($data)),
            '*/interface/checkpayment*' => Http::response($this->signed($this->payload($payment))),
        ]);
        app(PaymentService::class)->refresh($payment);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://www.paynow.co.zw/interface/trace'
            && $request['merchanttrace'] === str_repeat('a', 32) && isset($request['hash']));
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_merchant_trace_not_found_preserves_uncertain_payment(): void
    {
        $payment = $this->payment();
        $payment->update(['poll_url' => null, 'merchant_trace' => str_repeat('b', 32)]);
        Http::fake(['*/interface/trace' => Http::response($this->signed(['status' => 'NotFound']))]);
        app(PaymentService::class)->refresh($payment);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->next_check_at);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_trace_alone_cannot_fulfill_when_final_poll_disagrees(): void
    {
        $payment = $this->payment();
        $payment->update(['poll_url' => null, 'merchant_trace' => str_repeat('c', 32)]);
        $data = $this->payload($payment, overrides: ['pollurl' => 'https://www.paynow.co.zw/interface/checkpayment?guid=recovered']);
        Http::fake([
            '*/interface/trace' => Http::response($this->signed($data)),
            '*/interface/checkpayment*' => Http::response($this->signed($this->payload($payment, 'Created'))),
        ]);
        app(PaymentService::class)->refresh($payment);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_payment_confirmed_before_initialization_returns_keeps_pinned_provider_reference(): void
    {
        $user = User::factory()->create();
        Http::fake(function ($request) {
            $payment = BillingPayment::sole();
            if (str_ends_with($request->url(), '/initiatetransaction')) {
                $payment->update(['poll_url' => 'https://www.paynow.co.zw/interface/checkpayment?guid=early']);
                app(PaymentService::class)->refresh($payment);

                return Http::response($this->signed(['status' => 'Ok', 'browserurl' => 'https://www.paynow.co.zw/Payment/early',
                    'pollurl' => 'https://www.paynow.co.zw/interface/checkpayment?guid=early']));
            }

            return Http::response($this->signed($this->payload($payment)));
        });
        $this->actingAs($user)->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertRedirect('https://www.paynow.co.zw/Payment/early');
        $payment = BillingPayment::sole();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('PN'.$payment->id, $payment->provider_reference);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billing_receipts', 1);
    }

    public function test_confirmed_cancelled_renewal_preserves_current_access_and_allowance(): void
    {
        $payment = $this->payment();
        $user = User::findOrFail($payment->user_id);
        $subscription = $user->subscriptions()->create(['subscription_plan_id' => $payment->subscription_plan_id,
            'status' => 'active', 'starts_at' => now()->subDays(5), 'ends_at' => now()->addDays(20)]);
        $before = app(AiCreditService::class)->wallet($user)['credits'];
        Http::fake(['*' => Http::response($this->signed($this->payload($payment, 'Cancelled')))]);
        app(PaymentService::class)->refresh($payment);
        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame($before, app(AiCreditService::class)->wallet($user)['credits']);
        $this->assertDatabaseCount('subscriptions', 1);
    }
}
