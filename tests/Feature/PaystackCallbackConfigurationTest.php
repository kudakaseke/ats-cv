<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaystackCallbackConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['paystack.secret_key' => 'sk_test_fixture', 'paystack.test_payment_pages' => [
            'monthly' => ['page_id' => 7, 'url' => 'https://paystack.shop/pay/monthly-fixture'],
        ]]);
        Http::preventStrayRequests();
    }

    private function page(string $callback): array
    {
        return ['status' => true, 'data' => ['id' => 7, 'domain' => 'test', 'slug' => 'monthly-fixture',
            'name' => 'Monthly', 'redirect_url' => $callback, 'amount' => 7875]];
    }

    public function test_sync_changes_only_the_callback_and_verifies_the_saved_value(): void
    {
        $url = 'https://merchant.example/paystack/callback';
        Http::fake(['api.paystack.co/page/7' => Http::sequence()
            ->push($this->page('http://192.168.1.102:8001/paystack/callback'))
            ->push(['status' => true])->push($this->page($url))]);
        $this->artisan('paystack:sync-callbacks', ['--url' => $url])->assertSuccessful();
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->data() === ['redirect_url' => $url, 'name' => 'Monthly']);
    }

    public function test_correct_callback_is_not_written_again(): void
    {
        $url = 'https://merchant.example/paystack/callback';
        Http::fake(['api.paystack.co/page/7' => Http::response($this->page($url))]);
        $this->artisan('paystack:sync-callbacks', ['--url' => $url])->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_live_credentials_and_invalid_return_urls_are_rejected_before_contacting_paystack(): void
    {
        config(['paystack.secret_key' => 'sk_live_fixture']);
        $this->artisan('paystack:sync-callbacks', ['--url' => 'https://merchant.example/paystack/callback'])->assertFailed();
        config(['paystack.secret_key' => 'sk_test_fixture']);
        foreach (['javascript:alert(1)', 'https://merchant.example/wrong', 'https://name:password@merchant.example/paystack/callback',
            'https://merchant.example/paystack/callback?reference=forged'] as $url) {
            $this->artisan('paystack:sync-callbacks', ['--url' => $url])->assertFailed();
        }
        Http::assertNothingSent();
    }

    public function test_dry_run_uses_app_url_without_provider_requests(): void
    {
        config(['app.url' => 'https://merchant.example']);
        $this->artisan('paystack:sync-callbacks', ['--dry-run' => true])
            ->expectsOutput('Test-page callback: https://merchant.example/paystack/callback')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_wrong_merchant_page_is_rejected_before_any_update(): void
    {
        $page = $this->page('https://old.example/paystack/callback');
        $page['data']['domain'] = 'live';
        Http::fake(['api.paystack.co/page/7' => Http::response($page)]);
        $this->artisan('paystack:sync-callbacks', ['--url' => 'https://merchant.example/paystack/callback'])->assertFailed();
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_provider_ignoring_the_update_is_reported_as_failure(): void
    {
        Http::fake(['api.paystack.co/page/7' => Http::sequence()
            ->push($this->page('https://old.example/paystack/callback'))
            ->push(['status' => true])->push($this->page('https://old.example/paystack/callback'))]);
        $this->artisan('paystack:sync-callbacks', ['--url' => 'https://merchant.example/paystack/callback'])->assertFailed();
        Http::assertSentCount(3);
    }
}
