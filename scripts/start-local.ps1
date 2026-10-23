param([int]$Port = 8015)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpPath = (Get-Command php -ErrorAction Stop).Source
$logs = Join-Path $projectRoot 'storage\logs'
New-Item -ItemType Directory -Path $logs -Force | Out-Null

if (!(Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue))
{
    $router = Join-Path $projectRoot 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
    Start-Process -FilePath $phpPath -ArgumentList @('-d','upload_max_filesize=101M','-d','post_max_size=260M','-d','max_file_uploads=20','-S',"127.0.0.1:$Port",$router) -WorkingDirectory (Join-Path $projectRoot 'public') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs 'server.log') -RedirectStandardError (Join-Path $logs 'server-error.log')
}

$workerPidFile = Join-Path $logs 'worker.pid'
$existingWorker = $null
if (Test-Path -LiteralPath $workerPidFile)
{
    $workerId = [int](Get-Content -LiteralPath $workerPidFile)
    $existingWorker = Get-CimInstance Win32_Process -Filter "ProcessId=$workerId" -ErrorAction SilentlyContinue
}
if (!$existingWorker -or $existingWorker.CommandLine -notlike '*queue:work*--queue=audio*')
{
    $worker = Start-Process -FilePath $phpPath -ArgumentList @('artisan','queue:work','--queue=audio','--tries=3','--sleep=2','--timeout=0') -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs 'worker.log') -RedirectStandardError (Join-Path $logs 'worker-error.log') -PassThru
    Set-Content -LiteralPath $workerPidFile -Value $worker.Id
}
$importPidFile = Join-Path $logs 'imports-worker.pid'
$existingImportWorker = $null
if (Test-Path -LiteralPath $importPidFile)
{
    $importWorkerId = [int](Get-Content -LiteralPath $importPidFile)
    $existingImportWorker = Get-CimInstance Win32_Process -Filter "ProcessId=$importWorkerId" -ErrorAction SilentlyContinue
}
if (!$existingImportWorker -or $existingImportWorker.CommandLine -notlike '*queue:work*--queue=imports*')
{
    $importWorker = Start-Process -FilePath $phpPath -ArgumentList @('artisan','queue:work','--queue=imports','--tries=3','--sleep=2','--timeout=0') -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs 'imports-worker.log') -RedirectStandardError (Join-Path $logs 'imports-worker-error.log') -PassThru
    Set-Content -LiteralPath $importPidFile -Value $importWorker.Id
}

$schedulerPidFile = Join-Path $logs 'scheduler.pid'
$existingScheduler = $null
if (Test-Path -LiteralPath $schedulerPidFile)
{
    $schedulerId = [int](Get-Content -LiteralPath $schedulerPidFile)
    $existingScheduler = Get-CimInstance Win32_Process -Filter "ProcessId=$schedulerId" -ErrorAction SilentlyContinue
}
if (!$existingScheduler -or $existingScheduler.CommandLine -notlike '*artisan*schedule:work*')
{
    $scheduler = Start-Process -FilePath $phpPath -ArgumentList @('artisan','schedule:work') -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs 'scheduler.log') -RedirectStandardError (Join-Path $logs 'scheduler-error.log') -PassThru
    Set-Content -LiteralPath $schedulerPidFile -Value $scheduler.Id
}

Write-Host "Sales Call QA Agent: http://127.0.0.1:$Port (web server, upload preparation worker, audio worker, and scheduler running)."
