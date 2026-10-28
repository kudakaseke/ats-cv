param([int]$Port = 8001, [string]$BaseUrl)
$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$envPath = Join-Path $project '.env'
$settings = [IO.File]::ReadAllText($envPath)
if ($settings -notmatch '(?m)^APP_ENV=local\s*$' -or $settings -notmatch '(?m)^APP_DEBUG=false\s*$') {
    throw 'This launcher requires APP_ENV=local and APP_DEBUG=false.'
}
if (!$BaseUrl) {
    $network = Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -and $_.IPv4Address.IPAddress -notlike '169.254.*' } | Select-Object -First 1
    if (!$network) { throw 'No active IPv4 network found. Pass -BaseUrl explicitly.' }
    $BaseUrl = 'http://' + $network.IPv4Address.IPAddress + ':' + $Port
}
$BaseUrl = $BaseUrl.TrimEnd('/')
$address = [uri]$BaseUrl
if ($address.Scheme -ne 'http' -or $address.Port -ne $Port -or $address.AbsolutePath -ne '/' -or $address.Query -or $address.Fragment -or $address.UserInfo) {
    throw 'Use an HTTP base address on the selected local port, without a path, credentials or query.'
}
$listener = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
if ($listener) {
    $owner = Get-CimInstance Win32_Process -Filter ('ProcessId = ' + ($listener | Select-Object -First 1).OwningProcess)
    if (!$owner -or $owner.Name -ne 'php.exe' -or $owner.CommandLine -notlike ('*' + $project + '*server.php*')) {
        throw 'The selected port belongs to another server. Stop it or select another port.'
    }
}
$settings = [regex]::Replace($settings, '(?m)^APP_URL=.*$', ('APP_URL=' + $BaseUrl))
[IO.File]::WriteAllText($envPath, $settings)
$php = (Get-Command php -ErrorAction Stop).Source
Push-Location $project
try {
    & $php artisan config:clear
    if ($LASTEXITCODE -ne 0) { throw 'Unable to clear configuration.' }
    if (!$listener) {
        Start-Process -FilePath $php -ArgumentList @('artisan', 'serve', '--host=0.0.0.0', ('--port=' + $Port), '--no-reload') -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput (Join-Path $project 'storage\logs\paystack-local-server.log') -RedirectStandardError (Join-Path $project 'storage\logs\paystack-local-server-error.log') | Out-Null
    }
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + '/login') -TimeoutSec 3
            if ($response.StatusCode -eq 200) { $ready = $true; break }
        } catch { Start-Sleep -Milliseconds 300 }
    }
    if (!$ready) { throw 'The configured app URL is not reachable. Callback URLs were not changed at Paystack.' }
    & $php artisan paystack:sync-callbacks ('--url=' + $BaseUrl + '/paystack/callback')
    if ($LASTEXITCODE -ne 0) { throw 'Paystack callback sync failed. Check the page settings before making another payment.' }
    Write-Output ('Open ATSDetector here and sign in before checkout: ' + $BaseUrl)
    Write-Output 'Local callback only. Production webhooks require a public HTTPS application address.'
} finally { Pop-Location }
