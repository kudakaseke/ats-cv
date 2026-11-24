<?php

// Local temporary tunnel: expose only the payment notification endpoint.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! preg_match('~\A/billing/paynow/notify/(?:button-day|[0-9a-fA-F-]{36})\z~', $path)) {
    http_response_code(404);
    exit;
}
$body = file_get_contents('php://input', false, null, 0, 16385);
if (strlen($body) > 16384) {
    http_response_code(413);
    exit;
}
$context = stream_context_create(['http' => [
    'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => $body, 'ignore_errors' => true, 'timeout' => 15,
]]);
$result = @file_get_contents('http://127.0.0.1:8000'.$path, false, $context);
if ($result === false || ! preg_match('/^HTTP\/\S+\s+(\d{3})/', $http_response_header[0] ?? '', $match)) {
    http_response_code(502);
    exit;
}
http_response_code((int) $match[1]);
header('Content-Type: text/plain; charset=UTF-8');
echo $result;
