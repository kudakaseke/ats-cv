<?php

// Used only by the loopback-only, short-lived smoke-test server. No application route is added.
use App\Exceptions\SentrySetupTest;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\TransportInterface;

$token = getenv('SENTRY_WEB_PROBE_TOKEN');
if (PHP_SAPI !== 'cli-server' || ! preg_match('/^[a-f0-9]{32}$/', $token ?: '')
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);

    return;
}
if ($_SERVER['REQUEST_URI'] === '/__probe_ready') {
    header('Content-Type: application/json');
    echo '{"ready":true}';

    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || $_SERVER['REQUEST_URI'] !== '/__sentry_probe/'.$token) {
    http_response_code(404);

    return;
}
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('local') || ! config('sentry.dsn')) {
    http_response_code(403);
    echo 'Local environment and configured Sentry project required.';

    return;
}
$builder = app(ClientBuilder::class);
$transport = new class($builder->getTransport()) implements TransportInterface
{
    public ?string $status = null;

    public ?string $eventId = null;

    public function __construct(private TransportInterface $wrapped) {}

    public function send(Event $event): Result
    {
        $result = $this->wrapped->send($event);
        $this->status = (string) $result->getStatus();
        $this->eventId = (string) $event->getId();

        return $result;
    }

    public function close(?int $timeout = null): Result
    {
        return $this->wrapped->close($timeout);
    }
};
$app->instance(ClientBuilder::class, $builder->setTransport($transport));
$app->forgetInstance(HubInterface::class);
Route::middleware('web')->get('/__sentry_probe/'.$token, function () {
    throw new SentrySetupTest('Synthetic browser verification; no customer information.');
})->name('diagnostics.sentry.web');
$request = Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-Probe-Sentry-Status', $transport->status ?? 'NOT_SENT');
$response->headers->set('X-Probe-Sentry-Event', $transport->eventId ?? '');
$response->send();
$kernel->terminate($request, $response);
