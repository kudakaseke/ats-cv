<?php

use ATSCV\AI;
use ATSCV\BasicChecker;
use ATSCV\Billing;
use ATSCV\Config;
use ATSCV\Credits;
use ATSCV\DB;
use ATSCV\Documents;
use ATSCV\Install;
use ATSCV\Jobs;
use ATSCV\Paynow;
use ATSCV\Plugin;
use ATSCV\Privacy;
use ATSCV\Rest;
use ATSCV\Resume;
use ATSCV\UI;

// Runs only in the separately-created local WordPress sandbox. Never connects to provider APIs.
$metadata = json_decode(file_get_contents(__DIR__.'/.sandbox.json'), true, 512, JSON_THROW_ON_ERROR);
if (! str_starts_with($metadata['database'], 'atscv_plugin_test_') || ! str_contains(str_replace('\\', '/', $metadata['root']), '/storage/app/wordpress-test-')) {
    throw new RuntimeException('Invalid test sandbox.');
}
$destination = $metadata['root'].'/wp-content/plugins/ats-cv-detector';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($metadata['plugin'], FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
    $relative = substr($file->getPathname(), strlen($metadata['plugin']) + 1);
    $target = $destination.'/'.$relative;
    if ($file->isDir()) {
        if (! is_dir($target)) {
            mkdir($target, 0777, true);
        }
    } else {
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        } copy($file->getPathname(), $target);
    }
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8091';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PORT'] = '8091';
define('WP_INSTALLING', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
require $metadata['root'].'/wp-load.php';
add_filter('pre_wp_mail', static fn () => true);
$http = null;
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$http) {
    if ($http) {
        return $http($url, $args);
    }

    return new WP_Error('test_blocked', 'Unmocked HTTP is prohibited in plugin tests.');
}, 10, 3);
require_once ABSPATH.'wp-admin/includes/upgrade.php';
if (! is_blog_installed()) {
    wp_install('ATS CV Plugin Test', 'ats_test_admin', 'admin@example.invalid', false, '', 'Test-Only-Password-7654321');
}
require_once ABSPATH.'wp-admin/includes/plugin.php';
if (! is_plugin_active('ats-cv-detector/ats-cv-detector.php')) {
    $activation = activate_plugin('ats-cv-detector/ats-cv-detector.php');
    if (is_wp_error($activation)) {
        throw new RuntimeException($activation->get_error_message());
    }
} else {
    require_once $destination.'/ats-cv-detector.php';
}
Plugin::boot();
update_option('users_can_register', 1);
update_option('blog_public', 0);
update_option('permalink_structure', '/%postname%/');
switch_theme('twentytwentyfive');
global $wpdb;
$passed = 0;
$failed = 0;
$report = [];
function check(bool $condition, string $label): void
{
    global $passed,$failed,$report;
    $report[] = ['test' => $label, 'passed' => $condition];
    if ($condition) {
        $passed++;
        echo "PASS $label\n";
    } else {
        $failed++;
        echo "FAIL $label\n";
    }
}
function testUser(): int
{
    $user = wp_insert_user(['user_login' => 'fixture_'.bin2hex(random_bytes(5)), 'user_email' => 'fixture_'.bin2hex(random_bytes(5)).'@example.invalid', 'user_pass' => 'Test-Only-Password-7654321', 'role' => 'subscriber']);
    if (is_wp_error($user)) {
        throw new RuntimeException($user->get_error_message());
    } update_user_meta($user, 'atscv_email_verified', gmdate('c'));

    return $user;
}
function signedBody(array $data): string
{
    $data['hash'] = strtoupper(hash('sha512', implode('', $data).'test-integration-key-not-real'));

    return http_build_query($data);
}
function httpResponse(string $body): array
{
    return ['headers' => [], 'body' => $body, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
}
function payment(int $user, string $plan = 'monthly', string $mode = 'live', string $kind = 'subscription'): object
{
    $offer = Config::plans()[$plan];
    $id = DB::insert('payments', ['reference' => wp_generate_uuid4(), 'user_id' => $user, 'plan' => $plan, 'kind' => $kind, 'mode' => $mode, 'integration_id' => '123', 'amount_cents' => $offer['cents'], 'credits' => $offer['credits'], 'trace' => bin2hex(random_bytes(16)),
        'poll_url' => Config::crypt('https://www.paynow.co.zw/interface/checkpayment?guid=fixture'), 'created_at' => DB::now(), 'updated_at' => DB::now()]);
    global $wpdb;

    return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('payments').' WHERE id=%d', $id));
}
function payload(object $payment, string $status = 'Paid'): array
{
    return ['reference' => $payment->reference, 'amount' => number_format($payment->amount_cents / 100, 2, '.', ''), 'paynowreference' => 'PN'.$payment->id, 'status' => $status];
}
$mode = 'live';
add_filter('atscv_config', static function ($value, $name) use (&$mode) {
    return match ($name) {
        'PAYNOW_MODE' => $mode,'PAYNOW_LIVE_APPROVED' => true,default => $value
    };
}, 10, 2);
$admin = get_user_by('login', 'ats_test_admin');
wp_set_current_user($admin->ID);
check(current_user_can('atscv_manage_billing'), 'activation grants billing capability only to admin');
check(is_plugin_active('ats-cv-detector/ats-cv-detector.php'), 'real WordPress plugin activation');
check(get_post((int) get_option('atscv_page_id'))?->post_content === '[ats_cv_detector]', 'activation creates the workspace page');
check((bool) wp_next_scheduled('atscv_maintenance'), 'maintenance is scheduled');
Install::activate();
check((int) get_option('atscv_schema_version') >= 0 && $wpdb->get_var('SHOW TABLES LIKE "'.$wpdb->prefix.'atscv_payments"') !== null, 'schema migration is repeatable');
$user = testUser();
wp_set_current_user($user);
$wallet = Credits::wallet($user);
Credits::wallet($user);
check($wallet['available'] === 15, '15 welcome credits');
check((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('ledger').' WHERE user_id=%d AND type=%s', $user, 'welcome')) === 1, 'welcome allocation occurs once');
check(! current_user_can('atscv_manage_billing'), 'customer cannot manage billing');
$cv = Resume::normalize(['name' => 'Candidate Example', 'role' => 'Software Developer', 'email' => 'candidate@example.invalid', 'phone' => '+263 771 234 567', 'summary' => 'Developer with PHP and Laravel experience.', 'skills' => 'PHP, Laravel, SQL', 'experience' => [['title' => 'Developer', 'company' => 'Example Employer', 'period' => '2021 - Present', 'description' => 'Built PHP and Laravel applications.']], 'education' => [['degree' => 'BSc Computer Science', 'school' => 'Example University', 'period' => '2017 - 2020']]]);
$resume = Resume::save($user, 0, 0, 'Test CV', 'essential', $cv);
check($resume->revision === '1' || (int) $resume->revision === 1, 'save a private CV');
check(! str_contains($resume->content, 'Candidate Example'), 'CV contents are encrypted at rest');
$other = testUser();
check(Resume::find((int) $resume->id, $other) === null, 'cross-user CV ownership');
try {
    Resume::save($user, (int) $resume->id, 0, 'Wrong revision', 'essential', $cv);
    check(false, 'stale revision is rejected');
} catch (DomainException) {
    check(true, 'stale revision is rejected');
}
try {
    Resume::save($user, 0, 0, 'Second CV', 'essential', $cv);
    check(false, 'free saved-CV limit');
} catch (DomainException) {
    check(true, 'free saved-CV limit');
}
try {
    Resume::save($user, (int) $resume->id, 1, 'Test', 'classic', $cv);
    check(false, 'premium template lock');
} catch (DomainException) {
    check(true, 'premium template lock');
}
$op = Credits::reserve($user, str_repeat('a', 20), 'analyse', ['cv' => $cv, 'job' => 'PHP developer']);
check(Credits::wallet($user)['available'] === 10, 'analysis reserves five credits');
Credits::reserve($user, str_repeat('a', 20), 'analyse', ['cv' => $cv, 'job' => 'PHP developer']);
check(Credits::wallet($user)['available'] === 10, 'duplicate operation does not double spend');
try {
    Credits::reserve($user, str_repeat('a', 20), 'analyse', ['cv' => $cv, 'job' => 'Different']);
    check(false, 'input-hash mismatch rejected');
} catch (InvalidArgumentException) {
    check(true, 'input-hash mismatch rejected');
}
$op2 = Credits::reserve($user, str_repeat('b', 20), 'revamp', ['cv' => $cv, 'job' => 'PHP developer']);
try {
    Credits::reserve($user, str_repeat('c', 20), 'analyse', ['cv' => $cv]);
    check(false, 'insufficient credits rejected');
} catch (DomainException) {
    check(true, 'insufficient credits rejected');
}
DB::update('operations', ['state' => 'running', 'lease_token' => 'test-token'], ['id' => $op2->id]);
Credits::finish((int) $op2->id, 'test-token', null);
Credits::finish((int) $op2->id, 'test-token', null);
check(Credits::wallet($user)['available'] === 10, 'failed revamp restores credits once');
check(Paynow::signature(['id' => '1201', 'reference' => 'TEST REF', 'amount' => '99.99', 'additionalinfo' => 'A test ticket transaction', 'returnurl' => 'http://www.google.com/search?q=returnurl', 'resulturl' => 'http://www.google.com/search?q=resulturl', 'status' => 'Message'], '3e9fed89-60e1-4ce5-ab6e-6b1eb2d4f977') === '2A033FC38798D913D42ECB786B9B19645ADEDBDE788862032F1BD82CF3B92DEF84F316385D5B40DBB35F1A4FD7D5BFE73835174136463CDD48C9366B0749C689', 'official Paynow SHA512 vector');
foreach (['http://www.paynow.co.zw/test', 'https://127.0.0.1/test', 'https://www.paynow.co.zw.evil.test/test', 'https://user:pass@www.paynow.co.zw/test', 'https://www.paynow.co.zw:444/test'] as $url) {
    check(! Paynow::safeUrl($url), 'reject unsafe URL '.$url);
}
$calls = 0;
foreach (['Created' => 'pending', 'Sent' => 'pending', 'Paid' => 'paid', 'Awaiting Delivery' => 'paid', 'Delivered' => 'paid', 'Cancelled' => 'cancelled', 'Refunded' => 'reversed', 'Disputed' => 'reversed'] as $status => $expected) {
    $owner = testUser();
    $p = payment($owner);
    $http = static function ($url, $args) use ($p, $status, &$calls) {
        $calls++;

        return httpResponse(signedBody(payload($p, $status)));
    };
    Billing::refresh($p);
    $fresh = Billing::find($p->reference);
    check($fresh->state === $expected, 'Paynow status '.$status);
    if ($expected === 'paid') {
        Billing::refresh($fresh);
        check((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('periods').' WHERE payment_id=%d', $p->id)) === 1, 'one paid period for '.$status);
        check(Credits::wallet($owner)['available'] === 150, 'one allowance for '.$status);
    }
}
$owner = testUser();
$p = payment($owner);
$http = static fn () => httpResponse(signedBody(payload($p)));
$body = signedBody(payload($p));
check(Billing::notify($p->reference, $body)->get_status() === 200, 'valid notification');
check(Billing::notify($p->reference, $body)->get_status() === 200, 'duplicate notification');
check((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('receipts').' WHERE payment_id=%d', $p->id)) === 1, 'one receipt per payment');
check(Billing::notify($p->reference, 'reference=x&hash=forged')->get_status() === 400, 'forged notification rejected');
$wrong = payload($p);
$wrong['amount'] = '0.01';
check(Billing::notify($p->reference, signedBody($wrong))->get_status() === 400, 'wrong notification amount rejected');
$p2 = payment(testUser());
$wrong = payload($p2);
$wrong['amount'] = '0.01';
$http = static fn () => httpResponse(signedBody($wrong));
try {
    Billing::refresh($p2);
    check(false, 'wrong poll amount rejected');
} catch (RuntimeException) {
    check(true, 'wrong poll amount rejected');
}
$p2 = payment(testUser());
$http = static fn () => new WP_Error('timeout', 'Simulated provider outage');
try {
    Billing::refresh($p2);
} catch (RuntimeException) {
}check(Billing::find($p2->reference)->state === 'pending', 'provider timeout stays pending');
$mode = 'test';
$testOwner = testUser();
$test = payment($testOwner, 'monthly', 'test');
$http = static fn () => httpResponse(signedBody(payload($test)));
Billing::refresh($test);
check(Billing::find($test->reference)->state === 'test_paid', 'merchant test payment is test_paid');
check(Credits::wallet($testOwner)['available'] === 15, 'test payment never grants paid allowance');
check(Credits::current($testOwner) === null, 'test payment never activates live subscription');
$mode = 'live';
$p = Billing::find($p->reference);
$http = static fn () => httpResponse(signedBody(payload($p, 'Refunded')));
Billing::refresh($p);
Billing::refresh(Billing::find($p->reference));
check(Credits::wallet($owner)['available'] === 0, 'refund revokes associated allowance without double reversal');
check(Billing::endDate('2026-01-31 12:30:00', 'monthly') === '2026-02-28 12:30:00', 'month-end renewal date');
check(Billing::endDate('2024-02-29 12:30:00', 'annual') === '2025-02-28 12:30:00', 'leap-year annual date');
$renewUser = testUser();
$first = payment($renewUser);
$http = static fn () => httpResponse(signedBody(payload($first)));
Billing::refresh($first);
$current = Credits::current($renewUser);
$second = payment($renewUser, 'pro');
$http = static fn () => httpResponse(signedBody(payload($second)));
Billing::refresh($second);
$next = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('periods').' WHERE payment_id=%d', $second->id));
check($next->starts_at === $current->ends_at, 'early renewal and plan change preserve paid time');
check(Credits::wallet($renewUser)['available'] === 150, 'future Pro period does not refill current allowance');
$trace = payment(testUser());
DB::update('payments', ['poll_url' => null], ['id' => $trace->id]);
$trace = Billing::find($trace->reference);
$traceCalls = 0;
$http = static function ($url, $args) use ($trace, &$traceCalls) {
    $traceCalls++;
    $data = payload($trace);
    if (str_ends_with($url, '/trace')) {
        $data['pollurl'] = 'https://www.paynow.co.zw/interface/checkpayment?guid=recovered';
    }

return httpResponse(signedBody($data));
};
Billing::refresh($trace);
check($traceCalls === 2 && Billing::find($trace->reference)->state === 'paid', 'merchant-trace recovery followed by direct poll');
$pdf = Documents::pdf($cv, 'essential');
check(str_starts_with($pdf, '%PDF-'), 'real PDF generation');
$pdfPath = tempnam(sys_get_temp_dir(), 'atscv-pdf-');
file_put_contents($pdfPath, $pdf);
$extracted = Documents::extract($pdfPath, 'pdf');
unlink($pdfPath);
check(str_contains($extracted, 'Candidate Example'), 'PDF contains extractable CV text');
$docx = Documents::docx($cv, 'essential');
check(str_starts_with($docx, 'PK'), 'real DOCX ZIP generation');
$docxPath = tempnam(sys_get_temp_dir(), 'atscv-word-');
file_put_contents($docxPath, $docx);
$extracted = Documents::extract($docxPath, 'docx');
unlink($docxPath);
check(str_contains($extracted, 'Example Employer'), 'DOCX round-trip text extraction');
$assessment = ['summary' => 'PHP match', 'requirements' => [['category' => 'skills', 'requirement' => 'PHP', 'job_evidence' => 'PHP developer', 'cv_evidence' => 'PHP and Laravel', 'status' => 'matched', 'importance' => 'mandatory']], 'recommendations' => ['Keep examples.'], 'questions' => []];
$validated = AI::validate('analyse', $assessment, $cv, 'We need a PHP developer to build systems.');
check($validated['score'] === 100.0 || $validated['score'] === 100, 'assessment uses verified evidence');
$assessment['requirements'][0]['cv_evidence'] = 'invented specialist evidence';
$validated = AI::validate('analyse', $assessment, $cv, 'We need a PHP developer to build systems.');
check($validated['score'] === 0.0 || $validated['score'] === 0, 'invented evidence earns no match points');
$basic = (new BasicChecker)->check(Resume::text($cv));
check(isset($basic['checks'],$basic['scope']), 'basic checker is available without AI');
wp_set_current_user(0);
check(is_wp_error(Rest::permission()), 'private API rejects anonymous users');
wp_set_current_user($other);
delete_user_meta($other, 'atscv_email_verified');
check(is_wp_error(Rest::permission()), 'private API requires email verification');
update_user_meta($other, 'atscv_email_verified', DB::now());
do_action('rest_api_init');
$request = new WP_REST_Request('GET', '/atscv/v1/resumes/'.$resume->id);
$response = rest_get_server()->dispatch($request);
check($response->get_status() === 409, 'real REST route enforces CV ownership');
wp_set_current_user($user);
check(str_contains(do_shortcode('[ats_cv_detector]'), 'atscv-workspace'), 'shortcode renders the signed-in workspace');
Privacy::deleted($user);
check(Resume::find((int) $resume->id, $user) === null, 'privacy erasure removes private CV content');
check(Credits::wallet($user)['available'] === 15, 'privacy erasure cancels and refunds only unfinished operations');
Privacy::deleted($user);
check(Credits::wallet($user)['available'] === 15, 'repeated privacy erasure cannot regrant welcome credits');
$aiUser = testUser();
$aiOp = Credits::reserve($aiUser, str_repeat('d', 20), 'analyse', ['cv' => $cv, 'job' => 'We need a PHP developer to build systems.']);
$goodAssessment = ['summary' => 'Evidence-backed PHP match', 'requirements' => [['category' => 'skills', 'requirement' => 'PHP', 'job_evidence' => 'PHP developer', 'cv_evidence' => 'PHP and Laravel', 'status' => 'matched', 'importance' => 'mandatory']], 'recommendations' => ['Show relevant examples.'], 'questions' => []];
$http = static fn ($url, $args) => str_contains($url, 'api.openai.com') ? httpResponse(json_encode(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($goodAssessment)]]]]])) : new WP_Error('test_blocked', 'Provider polling is mocked.');
AI::process((int) $aiOp->id);
AI::process((int) $aiOp->id);
$finished = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('operations').' WHERE id=%d', $aiOp->id));
check($finished->state === 'completed' && (int) $finished->attempts === 1, 'background AI job completes once using a schema-valid fake response');
check(Credits::wallet($aiUser)['available'] === 10, 'completed AI job retains one five-credit charge');
$failedOp = Credits::reserve($aiUser, str_repeat('e', 20), 'analyse', ['cv' => $cv, 'job' => 'We need a PHP developer to build systems.']);
$http = static fn () => new WP_Error('test_outage', 'Simulated outage');
AI::process((int) $failedOp->id);
check(Credits::wallet($aiUser)['available'] === 10, 'provider failure automatically restores reserved credits');
$topupUser = testUser();
$topup = payment($topupUser, 'monthly', 'live', 'topup');
$http = static fn () => httpResponse(signedBody(payload($topup)));
Billing::refresh($topup);
check(Credits::wallet($topupUser)['available'] === 165, 'verified top-up adds 150 persistent purchased credits');
for ($i = 0; $i < 3; $i++) {
    $spend = Credits::reserve($topupUser, 'topup-operation-'.str_repeat((string) $i, 8), 'revamp', ['cv' => $cv, 'job' => 'Software work']);
    DB::update('operations', ['state' => 'running', 'lease_token' => 'topup-token'], ['id' => $spend->id]);
    Credits::finish((int) $spend->id, 'topup-token', ['summary' => 'Completed test operation']);
}
$http = static fn () => httpResponse(signedBody(payload($topup, 'Refunded')));
Billing::refresh($topup);
Billing::refresh(Billing::find($topup->reference));
check(Credits::wallet($topupUser)['debt'] === 15 && Credits::wallet($topupUser)['available'] === 0, 'consumed top-up reversal records debt only once');
$expireUser = testUser();
$expiring = payment($expireUser);
$http = static fn () => httpResponse(signedBody(payload($expiring)));
Billing::refresh($expiring);
$expiring = Billing::find($expiring->reference);
DB::update('periods', ['starts_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'ends_at' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => $expiring->period_id]);
check(Credits::wallet($expireUser)['available'] === 0 && Credits::current($expireUser) === null, 'expiry revokes allowance and access even without scheduler');
DB::update('payments', ['next_check_at' => gmdate('Y-m-d H:i:s', time() + 86400)], ['id' => $expiring->id]);
$http = static fn () => new WP_Error('test_blocked', 'No provider calls allowed.');
Jobs::maintenance();
check((bool) get_option('atscv_heartbeat'), 'maintenance records runner heartbeat');
check($wpdb->get_var($wpdb->prepare('SELECT state FROM '.DB::table('periods').' WHERE id=%d', $expiring->period_id)) === 'expired', 'scheduled maintenance marks expired periods');
$engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', DB::table('wallets')));
check(strtolower($engine) === 'innodb', 'wallet tables use transactional InnoDB');
$deleteUser = testUser();
wp_set_current_user($deleteUser);
$deleteRequest = new WP_REST_Request('POST', '/atscv/v1/account/delete');
$deleteRequest->set_param('password', 'wrong');
$deleteRequest->set_param('confirmation', 'DELETE');
try {
    Rest::deleteAccount($deleteRequest);
    check(false, 'account deletion requires reauthentication');
} catch (DomainException) {
    check(true, 'account deletion requires reauthentication');
}
wp_set_current_user($admin->ID);
check(str_contains(UI::render(), 'atscv-workspace'), 'administrator can access customer workspace');
function concurrent(array $commands): array
{
    $running = [];
    $results = [];
    foreach ($commands as $arguments) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__.'/concurrent-worker.php', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start parallel test.');
        }fclose($pipes[0]);
        $running[] = [$process, $pipes];
    }
    foreach ($running as [$process,$pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException('Parallel worker failed: '.$error);
        }$results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    return $results;
}
$parallelUser = testUser();
Credits::wallet($parallelUser);
$commands = [];
for ($i = 0; $i < 4; $i++) {
    $commands[] = ['reserve', (string) $parallelUser, 'parallel-spend-'.str_repeat((string) $i, 12)];
}
$parallel = concurrent($commands);
check(count(array_filter($parallel, static fn ($row) => $row['state'] === 'reserved')) === 3 && Credits::wallet($parallelUser)['available'] === 0, 'four simultaneous workers cannot overspend 15 credits');
$parallelUser = testUser();
Credits::wallet($parallelUser);
$parallel = concurrent(array_fill(0, 4, ['reserve', (string) $parallelUser, str_repeat('z', 20)]));
check(Credits::wallet($parallelUser)['available'] === 10 && count(array_unique(array_column($parallel,'id'))) === 1,'simultaneous duplicate operation reserves only once');
$parallelPayment = payment(testUser());
$parallel = concurrent(array_fill(0,4,['notify', $parallelPayment->reference]));
check(count(array_filter($parallel,static fn ($row) => $row['status'] === 200)) === 4 && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('periods').' WHERE payment_id=%d',$parallelPayment->id)) === 1,'simultaneous Paynow notifications fulfill one paid period');
check((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('receipts').' WHERE payment_id=%d',$parallelPayment->id)) === 1,'simultaneous payment notifications create one receipt');
// A persistent verified browser fixture is restricted to this isolated site.
$browser = get_user_by('login','ats_browser_fixture');
if (! $browser) {
    $browserId = wp_insert_user(['user_login' => 'ats_browser_fixture', 'user_email' => 'browser@example.invalid', 'user_pass' => 'Test-Only-Password-7654321', 'role' => 'subscriber']);
    update_user_meta($browserId,'atscv_email_verified',DB::now());
}
echo "\n$passed passed, $failed failed\n";
file_put_contents(__DIR__.'/report.json',json_encode(['passed' => $passed, 'failed' => $failed, 'checks' => $report],JSON_PRETTY_PRINT));
exit($failed ? 1 : 0);
