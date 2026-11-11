<?php

use ATSCV\Documents;

define('ABSPATH', __DIR__.'/');
require_once __DIR__.'/ats-cv-detector/src/Documents.php';
$files = [];
foreach (['ats-cv-original.php', 'README.md'] as $file) {
    $files['ats-cv-original/'.$file] = file_get_contents(__DIR__.'/ats-cv-original/'.$file);
}
$directory = dirname(__DIR__).'/storage/app/private/releases';
if (! is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$path = $directory.'/ats-cv-original-1.0.0.zip';
file_put_contents($path, Documents::zip($files, true));
echo $path.PHP_EOL.'SHA256 '.hash_file('sha256', $path).PHP_EOL;
