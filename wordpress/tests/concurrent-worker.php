<?php

use ATSCV\Billing;
use ATSCV\Credits;

$metadata = json_decode(file_get_contents(__DIR__.'/.sandbox.json'), true, 512, JSON_THROW_ON_ERROR);
if (! str_starts_with($metadata['database'], 'atscv_plugin_test_')) {
    exit(2);
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8091';
$_SERVER['REQUEST_URI'] = '/';
define('WP_HTTP_BLOCK_EXTERNAL', true);
require $metadata['root'].'/wp-load.php';
add_filter('atscv_config', static fn ($value, $name) => $name === 'PAYNOW_MODE' ? 'live' : $value, 10, 2);
$task = $argv[1] ?? '';
try {
    if ($task === 'reserve') {
        $user = (int) $argv[2];
        $key = $argv[3];
        $operation = Credits::reserve($user, $key, 'analyse', ['source' => 'parallel test']);
        echo json_encode(['state' => 'reserved', 'id' => (int) $operation->id]);
    } elseif ($task === 'notify') {
        $payment = Billing::find($argv[2]);
        if (! $payment) {
            throw new RuntimeException('Missing payment.');
        }
        $data = ['reference' => $payment->reference, 'amount' => number_format($payment->amount_cents / 100, 2, '.', ''), 'paynowreference' => 'PN'.$payment->id, 'status' => 'Paid'];
        $data['hash'] = strtoupper(hash('sha512', implode('', $data).'test-integration-key-not-real'));
        $body = http_build_query($data);
        add_filter('pre_http_request', static fn () => ['headers' => [], 'body' => $body, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []]);
        $response = Billing::notify($payment->reference, $body);
        echo json_encode(['status' => $response->get_status()]);
    }
} catch (DomainException) {
    echo json_encode(['state' => 'insufficient']);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
