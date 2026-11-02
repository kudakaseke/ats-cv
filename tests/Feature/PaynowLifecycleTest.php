<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

    // Independent implementation: never use the production signer to create test responses.
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
    public function test_early_renewal_extends_from_paid_end_and_does_not_refill_credits_early(string $slug, int $credits): void
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
        $this->travelTo($end->copy()->addSecond());
        $this->assertSame($credits, app(AiCreditService::class)->wallet($user)['credits']);
        $this->assertSame(2, DB::table('ai_credit_transactions')->where('type', 'subscription_allocation')->count());
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

    public function test_paid_plan_change_preserves_current_paid_time_and_allocates_new_plan_only_at_start(): void
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
        $this->assertSame(2400, app(AiCreditService::class)->wallet($user)['credits']);
        $this->travelTo($end->copy()->addSecond());
        $this->assertSame(225, app(AiCreditService::class)->wallet($user)['credits']);
    }

    public function test_unconfirmed_live_currency_cannot_contact_paynow(): void
    {
        config(['billing.paynow.live_currency_confirmed' => false]);
        $this->actingAs(User::factory()->create())->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertSessionHasErrors('billing');
        Http::assertNothingSent();
        $this->assertDatabaseCount('subscriptions', 0);
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
