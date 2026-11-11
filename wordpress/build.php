<?php

use ATSCV\Documents;

// Builds the distributable without requiring ext-zip. Never packages server configuration or test databases.
$root = __DIR__.'/ats-cv-detector';
if (! is_file($root.'/vendor/autoload.php')) {
    throw new RuntimeException('Install document dependencies before packaging.');
}
$output = dirname(__DIR__).'/storage/app/private/releases';
if (! is_dir($output) && ! mkdir($output, 0700, true)) {
    throw new RuntimeException('Cannot create release directory.');
}
$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (! $file->isFile()) {
        continue;
    }
    $relative = str_replace(chr(92), '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_starts_with($relative, '.') || str_starts_with($relative, 'dist/') || str_ends_with($relative, '.zip') || str_contains($relative, '/.git/')) {
        continue;
    }
    $files['ats-cv-detector/'.$relative] = file_get_contents($file->getPathname());
}
define('ABSPATH', __DIR__.'/');
require_once $root.'/src/Documents.php';
$path = $output.'/ats-cv-detector-1.0.0.zip';
file_put_contents($path, Documents::zip($files, true));
echo $path.PHP_EOL;
echo count($files).' packaged files; '.filesize($path).' bytes'.PHP_EOL;
echo 'SHA256 '.hash_file('sha256', $path).PHP_EOL;
