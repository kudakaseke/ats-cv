param([string]$WordPressSource = 'C:/xampp/htdocs/wordpress', [string]$Mysql = 'C:/xampp/mysql/bin/mysql.exe')
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$testSuffix = [DateTime]::UtcNow.ToString('yyyyMMddHHmmss') + '_' + ([Guid]::NewGuid().ToString('N').Substring(0, 6))
$testDatabase = 'atscv_plugin_test_' + $testSuffix
$sandboxPath = Join-Path $projectRoot ('storage/app/wordpress-test-' + $testSuffix)
New-Item -ItemType Directory -Path $sandboxPath -Force | Out-Null
Get-ChildItem -LiteralPath $WordPressSource -File | Where-Object { $_.Name -ne 'wp-config.php' } | Copy-Item -Destination $sandboxPath
foreach ($directory in @('wp-admin', 'wp-includes')) { Copy-Item -LiteralPath (Join-Path $WordPressSource $directory) -Destination (Join-Path $sandboxPath $directory) -Recurse }
New-Item -ItemType Directory -Path (Join-Path $sandboxPath 'wp-content/themes') -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $sandboxPath 'wp-content/plugins') -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $WordPressSource 'wp-content/themes/twentytwentyfive') -Destination (Join-Path $sandboxPath 'wp-content/themes/twentytwentyfive') -Recurse
& $Mysql '-u' 'root' '-e' ('CREATE DATABASE `' + $testDatabase + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
if ($LASTEXITCODE -ne 0) { throw 'Could not create isolated test database.' }
$configText = @'
<?php
define('DB_NAME', '__DATABASE__');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wpt_';
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', true);
define('DISABLE_WP_CRON', true);
define('WP_HOME', 'http://127.0.0.1:8091');
define('WP_SITEURL', 'http://127.0.0.1:8091');
define('AUTH_KEY', 'local-wordpress-plugin-test-auth-not-production');
define('SECURE_AUTH_KEY', 'local-wordpress-plugin-test-secure-not-production');
define('LOGGED_IN_KEY', 'local-wordpress-plugin-test-login-not-production');
define('NONCE_KEY', 'local-wordpress-plugin-test-nonce-not-production');
define('AUTH_SALT', 'local-test-salt-1');
define('SECURE_AUTH_SALT', 'local-test-salt-2');
define('LOGGED_IN_SALT', 'local-test-salt-3');
define('NONCE_SALT', 'local-test-salt-4');
define('ATSCV_PAYNOW_ENABLED', true);
define('ATSCV_PAYNOW_ID', '123');
define('ATSCV_PAYNOW_KEY', 'test-integration-key-not-real');
define('ATSCV_OPENAI_KEY', 'test-ai-key-not-real');
define('ATSCV_PAYNOW_MODE', 'test');
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
'@
[System.IO.File]::WriteAllText((Join-Path $sandboxPath 'wp-config.php'), $configText.Replace('__DATABASE__', $testDatabase), (New-Object System.Text.UTF8Encoding($false)))
$metadata = @{ root = $sandboxPath; database = $testDatabase; plugin = (Join-Path $projectRoot 'wordpress/ats-cv-detector') }
[System.IO.File]::WriteAllText((Join-Path $PSScriptRoot '.sandbox.json'), ($metadata | ConvertTo-Json), (New-Object System.Text.UTF8Encoding($false)))
Write-Output ('Created isolated WordPress test database: ' + $testDatabase)
Write-Output ('Test site files: ' + $sandboxPath)
