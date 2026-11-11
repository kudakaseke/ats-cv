<?php

$metadata=json_decode(file_get_contents(__DIR__.'/.sandbox.json'),true,512,JSON_THROW_ON_ERROR);
if(!str_starts_with($metadata['database'],'atscv_plugin_test_'))throw new RuntimeException('Invalid test sandbox.');
$_SERVER['HTTP_HOST']='127.0.0.1:8091';$_SERVER['REQUEST_URI']='/';
define('WP_HTTP_BLOCK_EXTERNAL',true);
require $metadata['root'].'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
$zip=dirname(__DIR__,2).'/storage/app/private/releases/ats-cv-detector-1.0.0.zip';
if(!is_file($zip))throw new RuntimeException('Build the release first.');
WP_Filesystem();
$directory=$metadata['root'].'/wp-content/upgrade/atscv-package-'.bin2hex(random_bytes(4));
$result=unzip_file($zip,$directory);
if(is_wp_error($result))throw new RuntimeException($result->get_error_message());
$header=get_plugin_data($directory.'/ats-cv-detector/ats-cv-detector.php',false,false);
if($header['Name']!=='ATS CV Detector'||$header['Version']!=='1.0.0')throw new RuntimeException('Invalid plugin header.');
foreach(['vendor/autoload.php','vendor/dompdf/dompdf/LICENSE.LGPL','assets/app.js','README.md']as$required)if(!is_file($directory.'/ats-cv-detector/'.$required))throw new RuntimeException('Missing package file: '.$required);
$secrets=[];
foreach(file(dirname(__DIR__,2).'/.env',FILE_IGNORE_NEW_LINES)as$line){
    if(preg_match('/^([A-Z0-9_]*(?:KEY|SECRET|PASSWORD|TOKEN)[A-Z0-9_]*)=(.+)$/',$line,$matches)){
        $value=trim($matches[2]," \t\r\n\"'");if(strlen($value)>=12)$secrets[]=$value;
    }
}
$files=0;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$file){
    if(!$file->isFile())continue;
    if(in_array($file->getFilename(),['.env','wp-config.php','.sandbox.json'],true))throw new RuntimeException('Server configuration was packaged.');
    $content=file_get_contents($file->getPathname());
    foreach($secrets as$secret)if(str_contains($content,$secret))throw new RuntimeException('A current environment credential was found in the release.');
    $files++;
}
echo 'PASS: WordPress unpacked '.$files.' files using its plugin ZIP extraction path; plugin header, document dependencies, licenses and credential exclusion verified.'.PHP_EOL;
