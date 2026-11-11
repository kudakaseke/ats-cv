param([int]$Port = 8000)
$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$binary = Join-Path $env:LOCALAPPDATA 'atscvdetector\tools\cloudflared.exe'
if (!(Test-Path -LiteralPath $binary)) {
    throw 'cloudflared is missing. Download the Windows amd64 executable from https://developers.cloudflare.com/tunnel/downloads/ to the configured tools directory.'
}
$envPath = Join-Path $project '.env'
$settings = [IO.File]::ReadAllText($envPath)
if ($settings -notmatch '(?m)^APP_DEBUG=false\s*$') {
    throw 'Set APP_DEBUG=false before exposing the local application.'
}
$logDirectory = Join-Path $project 'storage\logs'
$runId = [Guid]::NewGuid().ToString('N')
$outputLog = Join-Path $logDirectory ('paynow-tunnel-'+$runId+'.log')
$errorLog = Join-Path $logDirectory ('paynow-tunnel-'+$runId+'-error.log')
$process = Start-Process -FilePath $binary -ArgumentList @('tunnel','--url',('http://127.0.0.1:'+$Port),'--protocol','http2','--no-autoupdate') -WindowStyle Hidden -RedirectStandardOutput $outputLog -RedirectStandardError $errorLog -PassThru
$address = $null
for ($attempt = 0; $attempt -lt 50; $attempt++) {
    Start-Sleep -Milliseconds 500
    $process.Refresh()
    if ($process.HasExited) { throw ('Cloudflare exited. Inspect '+$errorLog) }
    $logs = (Get-Content -LiteralPath $outputLog -Raw -ErrorAction SilentlyContinue)+(Get-Content -LiteralPath $errorLog -Raw -ErrorAction SilentlyContinue)
    if ($logs -match 'https://[a-z0-9-]+\.trycloudflare\.com') { $address = $Matches[0]; break }
}
if (!$address) {
    Stop-Process -Id $process.Id
    throw ('Cloudflare did not return a URL. Inspect '+$errorLog)
}
# Re-read after tunnel startup so concurrent edits to credentials are preserved.
$settings = [IO.File]::ReadAllText($envPath)
if ($settings -match '(?m)^PAYNOW_CALLBACK_URL=.*$') {
    $settings = [regex]::Replace($settings, '(?m)^PAYNOW_CALLBACK_URL=.*$', ('PAYNOW_CALLBACK_URL='+$address))
} else {
    $settings += "`r`nPAYNOW_CALLBACK_URL=$address`r`n"
}
[IO.File]::WriteAllText($envPath, $settings)
Push-Location $project
try { php artisan config:clear; if ($LASTEXITCODE -ne 0) { throw 'Unable to clear configuration.' } } finally { Pop-Location }
Write-Output ('Paynow test URL: '+$address)
Write-Output ('Cloudflare process ID: '+$process.Id)
Write-Output ('Stop this tunnel: Stop-Process -Id '+$process.Id)
Write-Output 'Keep Laravel running and use the HTTPS URL to start checkout. In local mode, Paynow returns to APP_URL so a temporary tunnel expiry will not strand the browser after payment.'
