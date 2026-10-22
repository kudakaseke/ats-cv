<?php

use App\Services\CvWordWriter;

// Standalone document-worker entry point; no Laravel boot, database or app secrets.
require __DIR__.'/../app/Services/CvWordWriter.php';

if (count($argv) !== 3) {
    exit(2);
}
$document = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
(new CvWordWriter)->write($document, $argv[2]);
