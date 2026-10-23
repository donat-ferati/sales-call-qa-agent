param(
    [string]$OllamaPath = "$env:LOCALAPPDATA\Programs\Ollama\ollama.exe",
    [switch]$SkipOllama,
    [switch]$PrepareSamples
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$toolsRoot = Join-Path $projectRoot 'tools'
$manifest = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'local-ai-assets.json') -Raw | ConvertFrom-Json
New-Item -ItemType Directory -Path $toolsRoot,(Join-Path $toolsRoot 'models') -Force | Out-Null

function Get-VerifiedAsset([string]$Uri, [string]$Destination, [string]$Sha256)
{
    $valid = (Test-Path -LiteralPath $Destination) -and ((Get-FileHash -LiteralPath $Destination -Algorithm SHA256).Hash -eq $Sha256)

    if (!$valid)
    {
        Write-Host "Downloading $Uri"
        Invoke-WebRequest -Uri $Uri -OutFile $Destination -UseBasicParsing
    }

    if ((Get-FileHash -LiteralPath $Destination -Algorithm SHA256).Hash -ne $Sha256)
    {
        throw "Checksum mismatch for $Destination. Do not execute this asset."
    }
}

$whisperArchive = Join-Path $toolsRoot 'whisper-bin-x64.zip'
Get-VerifiedAsset $manifest.whisper.source $whisperArchive $manifest.whisper.sha256
Expand-Archive -LiteralPath $whisperArchive -DestinationPath (Join-Path $toolsRoot 'whisper') -Force

$ffmpegArchive = Join-Path $toolsRoot 'ffmpeg-release-essentials.zip'
Get-VerifiedAsset $manifest.ffmpeg.source $ffmpegArchive $manifest.ffmpeg.sha256
Expand-Archive -LiteralPath $ffmpegArchive -DestinationPath (Join-Path $toolsRoot 'ffmpeg') -Force

$modelPath = Join-Path $toolsRoot 'models\ggml-small.en.bin'
$modelUri = "https://huggingface.co/ggerganov/whisper.cpp/resolve/$($manifest.whisper_model.revision)/ggml-small.en.bin"
Get-VerifiedAsset $modelUri $modelPath $manifest.whisper_model.sha256

Invoke-WebRequest -Uri 'https://raw.githubusercontent.com/ggml-org/whisper.cpp/b5454/LICENSE' -OutFile (Join-Path $toolsRoot 'whisper\LICENSE') -UseBasicParsing
Invoke-WebRequest -Uri 'https://raw.githubusercontent.com/openai/whisper/v20250625/LICENSE' -OutFile (Join-Path $toolsRoot 'models\WHISPER-LICENSE') -UseBasicParsing

if (!$SkipOllama)
{
    if (!(Test-Path -LiteralPath $OllamaPath))
    {
        throw 'Ollama was not found. Install Ollama from https://ollama.com/download/windows or pass -OllamaPath.'
    }

    try
    {
        $null = Invoke-RestMethod -Uri 'http://127.0.0.1:11434/api/tags' -TimeoutSec 3
    }
    catch
    {
        $env:OLLAMA_HOST = '127.0.0.1:11434'
        $env:OLLAMA_NUM_PARALLEL = '1'
        $env:OLLAMA_MAX_LOADED_MODELS = '1'
        Start-Process -FilePath $OllamaPath -ArgumentList 'serve' -WindowStyle Hidden -RedirectStandardOutput (Join-Path $toolsRoot 'ollama.stdout.log') -RedirectStandardError (Join-Path $toolsRoot 'ollama.stderr.log')
        $ready = $false

        for ($attempt = 0; $attempt -lt 10; $attempt++)
        {
            Start-Sleep -Seconds 1

            try
            {
                $null = Invoke-RestMethod -Uri 'http://127.0.0.1:11434/api/tags' -TimeoutSec 2
                $ready = $true
                break
            }
            catch {}
        }

        if (!$ready)
        {
            throw 'Ollama did not become ready. Inspect tools/ollama.stderr.log.'
        }
    }

    & $OllamaPath pull qwen3:14b-q4_K_M

    if ($LASTEXITCODE -ne 0)
    {
        throw 'Ollama model download failed.'
    }

    $installed = (Invoke-RestMethod -Uri 'http://127.0.0.1:11434/api/tags').models | Where-Object name -eq 'qwen3:14b-q4_K_M'
    Write-Host "Qwen3 model digest: $($installed.digest)"
}

if ($PrepareSamples)
{
    $samplesRoot = Join-Path $projectRoot 'samples'
    $sourceRoot = Join-Path $samplesRoot 'source'
    New-Item -ItemType Directory -Path $sourceRoot -Force | Out-Null
    $sourceBase = 'https://raw.githubusercontent.com/cricketclub/gridspace-stanford-harper-valley/0bd721e877c4a85d8c13ff837e68661ea6200a98'
    $ffmpeg = Join-Path $toolsRoot 'ffmpeg\ffmpeg-9.0.2-essentials_build\bin\ffmpeg.exe'
    $calls = @(
        @{ Id = 'ff0296d00e5e4184'; Name = 'branch-hours-complete' },
        @{ Id = '0002f70f7386445b'; Name = 'card-replacement-missing-next-step' }
    )

    foreach ($call in $calls)
    {
        foreach ($role in @('agent', 'caller'))
        {
            Invoke-WebRequest -Uri "$sourceBase/data/audio/$role/$($call.Id).wav" -OutFile (Join-Path $sourceRoot "$($call.Id)-$role.wav") -UseBasicParsing
        }

        $referencePath = Join-Path $sourceRoot "$($call.Id)-reference.json"
        Invoke-WebRequest -Uri "$sourceBase/data/transcript/$($call.Id).json" -OutFile $referencePath -UseBasicParsing
        $segments = Get-Content -LiteralPath $referencePath -Raw | ConvertFrom-Json
        $agent = $segments | Where-Object speaker_role -eq 'agent' | Select-Object -First 1
        $caller = $segments | Where-Object speaker_role -eq 'caller' | Select-Object -First 1
        $agentDelay = $agent.offset_ms - $agent.start_ms
        $callerDelay = $caller.offset_ms - $caller.start_ms
        $filter = "[0:a]adelay=$($agentDelay):all=1[a];[1:a]adelay=$($callerDelay):all=1[c];[a][c]amix=inputs=2:duration=longest:dropout_transition=0:normalize=0,alimiter=limit=0.95:level=false:latency=true[out]"
        & $ffmpeg -hide_banner -loglevel error -y -i (Join-Path $sourceRoot "$($call.Id)-agent.wav") -i (Join-Path $sourceRoot "$($call.Id)-caller.wav") -filter_complex $filter -map '[out]' -ar 16000 -ac 1 -c:a pcm_s16le (Join-Path $samplesRoot "$($call.Name).wav")

        if ($LASTEXITCODE -ne 0)
        {
            throw "Sample mix failed for $($call.Id)."
        }
    }

    & $ffmpeg -hide_banner -loglevel error -y -i (Join-Path $samplesRoot 'branch-hours-complete.wav') -t 20 -c:a pcm_s16le (Join-Path $samplesRoot 'branch-hours-truncated-demo.wav')

    if ($LASTEXITCODE -ne 0)
    {
        throw 'Truncated demonstration preparation failed.'
    }
}

Write-Host 'Local AI assets are ready. The application defaults resolve their paths from the project directory.'
