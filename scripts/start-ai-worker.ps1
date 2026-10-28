param()

$workspacePath = Split-Path -Parent $PSScriptRoot
$artisanPath = Join-Path $workspacePath 'artisan'
$existingWorker = Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'php.exe' -and $_.CommandLine -like "*$artisanPath*" -and $_.CommandLine -match 'queue:work ai'
}
if ($existingWorker) {
    Write-Output "AI worker already running: $($existingWorker.ProcessId -join ', ')"
    exit 0
}
$phpBinary = (Get-Command php -ErrorAction Stop).Source
$workerProcess = Start-Process -FilePath $phpBinary `
    -ArgumentList @('"' + $artisanPath + '"', 'queue:work', 'ai', '--queue=ai', '--sleep=1', '--timeout=360', '--tries=1') `
    -WorkingDirectory $workspacePath -WindowStyle Hidden `
    -RedirectStandardOutput (Join-Path $workspacePath 'storage/logs/ai-worker.log') `
    -RedirectStandardError (Join-Path $workspacePath 'storage/logs/ai-worker-error.log') -PassThru
Write-Output "AI worker started: $($workerProcess.Id)"
