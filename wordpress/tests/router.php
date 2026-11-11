<?php

$root = realpath($_SERVER['DOCUMENT_ROOT']);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = realpath($root.$path);
if ($file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require $root.'/index.php';
