<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaynowButtonNotificationController extends Controller
{
    public function receive(Request $request)
    {
        $key = config('billing.paynow.day_button_key');
        abort_unless(is_string($key) && $key !== '', 503);
        $body = $request->getContent();
        abort_if(strlen($body) > 16384, 413);
        $fields = [];
        $signed = '';
        foreach (explode('&', $body) as $pair) {
            abort_unless(str_contains($pair, '='), 400);
            [$rawName, $rawValue] = explode('=', $pair, 2);
            $name = urldecode($rawName);
            $value = urldecode($rawValue);
            $normalized = strtolower($name);
            abort_unless($name !== '' && ! isset($fields[$normalized]), 400);
            $fields[$normalized] = $value;
            if ($normalized !== 'hash') {
                $signed .= $name.$value;
            }
        }
        $hash = $fields['hash'] ?? '';
        abort_unless((bool) preg_match('/\A[0-9a-fA-F]{128}\z/', $hash)
            && hash_equals(strtoupper(hash('sha512', $signed.$key)), strtoupper($hash)), 400);
        $reference = $fields['paynow_reference'] ?? '';
        $amount = $fields['transaction_amount'] ?? '';
        abort_unless((bool) preg_match('/\A[a-zA-Z0-9-]{1,80}\z/', $reference) && $amount === '0.10', 400);
        $status = strtolower($fields['status'] ?? '');
        $payload = ['type' => 'day_button', 'paynow_reference' => $reference, 'transaction_amount' => $amount,
            'reported_status' => in_array($status, ['paid', 'pending', 'cancelled', 'failed', 'refunded', 'disputed'], true) ? $status : null,
            'has_ats_reference' => ! empty($fields['ats_payment_reference']),
            'has_poll_url' => ! empty($fields['pollurl'])];
        DB::table('payment_notifications')->insertOrIgnore([
            'billing_payment_id' => null,
            'fingerprint' => hash('sha256', 'day_button:'.$reference),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'hash_valid' => true,
            'error' => 'Button payment requires manual reconciliation; customer and payment status are not verified.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response('OK');
    }
}
