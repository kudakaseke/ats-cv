<?php

$metadata = json_decode(file_get_contents(__DIR__.'/.sandbox.json'), true, 512, JSON_THROW_ON_ERROR);
if (! str_starts_with($metadata['database'], 'atscv_plugin_test_')) {
    throw new RuntimeException('Invalid sandbox.');
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8091';
$_SERVER['REQUEST_URI'] = '/';
define('WP_HTTP_BLOCK_EXTERNAL', true);
require $metadata['root'].'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/file.php';
$source = dirname(__DIR__).'/ats-cv-original';
$target = WP_PLUGIN_DIR.'/ats-cv-original';
wp_mkdir_p($target);
foreach (['ats-cv-original.php', 'README.md'] as $file) {
    copy($source.'/'.$file, $target.'/'.$file);
}
$error = activate_plugin('ats-cv-original/ats-cv-original.php');
if (is_wp_error($error)) {
    throw new RuntimeException($error->get_error_message());
}
require_once $target.'/ats-cv-original.php';
$checks = 0;
$assert = static function ($condition, $message) use (&$checks) {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$assert(is_plugin_active('ats-cv-original/ats-cv-original.php'), 'Activation failed');
$id = (int) get_option('atscv_original_page');
$assert(get_post($id)->post_content === '[ats_cv_original]', 'Missing application page');
ATS_CV_Original::activate();
$assert((int) get_option('atscv_original_page') === $id, 'Activation duplicated page');
foreach (['javascript:alert(1)', 'https://user:password@example.com', '/app', 'https://example.com/?next=x', 'https://example.com/#x', ['bad']] as $url) {
    $assert(ATS_CV_Original::url($url) === '', 'Unsafe configuration accepted');
}
$assert(ATS_CV_Original::url('https://app.example.com/') === 'https://app.example.com', 'Valid URL rejected');
update_option('atscv_original_url', home_url('/'));
$assert(ATS_CV_Original::destination() === '', 'Home redirect loop allowed');
update_option('atscv_original_url', get_permalink($id));
$assert(ATS_CV_Original::destination() === '', 'Page redirect loop allowed');
update_option('atscv_original_url', 'https://app.example.com');
$_GET['redirect_to'] = 'https://attacker.invalid';
$assert(ATS_CV_Original::destination() === 'https://app.example.com', 'Visitor controlled destination');
$assert(str_contains(do_shortcode('[ats_cv_original]'), 'href="https://app.example.com"'), 'Shortcode failed');
$GLOBALS['wp_query'] = new WP_Query(['page_id' => $id]);
$redirected = null;
$capture = static function ($location, $status) use (&$redirected) {
    $redirected = [$location, $status];
    throw new RuntimeException('captured-connector-redirect');
};
add_filter('wp_redirect', $capture, 10, 2);
try {
    ATS_CV_Original::launch();
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'captured-connector-redirect') {
        throw $exception;
    }
}
remove_filter('wp_redirect', $capture, 10);
$assert($redirected === ['https://app.example.com', 302], 'Application page did not redirect correctly');
$GLOBALS['wp_query'] = new WP_Query(['page_id' => $id, 'preview' => true]);
$redirected = null;
ATS_CV_Original::launch();
$assert($redirected === null, 'Preview unexpectedly redirected');
WP_Filesystem();
$zip = dirname(__DIR__, 2).'/storage/app/private/releases/ats-cv-original-1.0.0.zip';
$extract = $metadata['root'].'/wp-content/upgrade/atscv-original-'.bin2hex(random_bytes(4));
$result = unzip_file($zip, $extract);
$assert(! is_wp_error($result), 'WordPress could not extract release');
$header = get_plugin_data($extract.'/ats-cv-original/ats-cv-original.php', false, false);
$assert($header['Version'] === '1.0.0', 'Invalid release metadata');
foreach (file(dirname(__DIR__, 2).'/.env', FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/^([A-Z0-9_]*(?:KEY|SECRET|PASSWORD|TOKEN)[A-Z0-9_]*)=(.+)$/', $line, $matches)) {
        $secret = trim($matches[2], " \t\r\n\"'");
        if (strlen($secret) < 12) {
            continue;
        }
        foreach (['ats-cv-original.php', 'README.md'] as $file) {
            $assert(! str_contains(file_get_contents($extract.'/ats-cv-original/'.$file), $secret), 'Credential included');
        }
    }
}
echo "PASS: {$checks} WordPress connector and release checks.\n";
