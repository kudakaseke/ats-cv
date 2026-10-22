<?php

return [
    'driver' => env('CV_DOCUMENT_DRIVER', 'local'),
    'remote_url' => env('DOCUMENT_SERVICE_URL'),
    'remote_key' => env('DOCUMENT_SERVICE_KEY'),
    'allow_local_http' => env('DOCUMENT_SERVICE_ALLOW_LOCAL_HTTP', false),
    'python' => env('CV_PYTHON_BINARY', base_path(PHP_OS_FAMILY === 'Windows' ? '.venv-ats/Scripts/python.exe' : '.venv-ats/bin/python')),
    'node' => env('CV_NODE_BINARY', PHP_OS_FAMILY === 'Windows' ? 'C:/Program Files/nodejs/node.exe' : 'node'),
    'browser' => env('CV_BROWSER_CHANNEL', 'chrome'),
];
