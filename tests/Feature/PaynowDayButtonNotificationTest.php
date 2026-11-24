<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaynowDayButtonNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_button_notification_is_recorded_once_without_granting_credits(): void
    {
        config(['billing.paynow.day_button_key' => 'button-test-key']);
        $fields = ['Paynow_Reference' => 'PN123', 'Transaction_Amount' => '0.10', 'Customer_Name' => 'Example Person'];
        $input = '';
        foreach ($fields as $name => $value) {
            $input .= $name.$value;
        }
        $fields['Hash'] = strtoupper(hash('sha512', $input.'button-test-key'));
        $body = http_build_query($fields);
        $this->call('POST', '/billing/paynow/notify/button-day', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)->assertOk();
        $this->call('POST', '/billing/paynow/notify/button-day', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)->assertOk();
        $this->assertDatabaseCount('payment_notifications', 1);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('ai_credit_transactions', 0);
        $this->assertDatabaseHas('payment_notifications', ['billing_payment_id' => null, 'hash_valid' => true]);
        $payload = json_decode(\Illuminate\Support\Facades\DB::table('payment_notifications')->sole()->payload, true);
        $this->assertNull($payload['reported_status']);
        $this->assertFalse($payload['has_ats_reference']);
        $this->assertArrayNotHasKey('customer_name', $payload);
    }

    public function test_missing_key_and_tampered_notification_are_rejected(): void
    {
        config(['billing.paynow.day_button_key' => null]);
        $this->call('POST', '/billing/paynow/notify/button-day', [], [], [], [], 'Hash=invalid')->assertStatus(503);
        config(['billing.paynow.day_button_key' => 'button-test-key']);
        $this->call('POST', '/billing/paynow/notify/button-day', [], [], [], [], 'Paynow_Reference=PN123&Transaction_Amount=0.10&Hash='.str_repeat('0', 128))->assertBadRequest();
        $this->assertDatabaseCount('payment_notifications', 0);
    }
}
