# Sales Call QA Agent

A local application for uploading sales recordings, transcribing them with whisper.cpp, evaluating them with Qwen3 14B through Ollama, and reviewing evidence and scores against versioned QA templates.

## Requirements

Use Windows with Laragon, PHP 8.3, Composer 2, Node.js 24 with npm, MySQL 8.4, and [Ollama for Windows](https://ollama.com/download/windows). PHP must have `pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `openssl`, and `zip` enabled. Run the commands below in PowerShell with `php`, `composer`, `node`, `npm`, and `ollama` available on your PATH.

The AI setup downloads Whisper and FFmpeg binaries, the `small.en` transcription model, and `qwen3:14b-q4_K_M`. Internet access is required for dependency and model downloads. Recordings are processed locally afterward.

## First-time setup

### 1. Start MySQL

Open Laragon and start its MySQL service. The application uses MySQL, including a separate database reserved for testing.

### 2. Open the project directory

```powershell
Set-Location 'C:\laragon\www\sales-call-qa-agent'
```

### 3. Check the installed tools

```powershell
php --version
composer --version
node --version
npm --version
ollama --version
```

### 4. Install PHP dependencies

```powershell
composer install
```

### 5. Install frontend dependencies

```powershell
npm ci
```

### 6. Create the environment file

This keeps an existing `.env` file when setting up an existing checkout.

```powershell
if (!(Test-Path -LiteralPath '.env')) {
    Copy-Item -LiteralPath '.env.example' -Destination '.env'
}
notepad .env
```

Set these values in `.env`, adjusting the database username and password to match your Laragon installation:

```dotenv
APP_URL=http://127.0.0.1:8015
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sales_call_qa_agent
DB_USERNAME=root
DB_PASSWORD=
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=2400
OLLAMA_URL=http://127.0.0.1:11434
OLLAMA_MODEL=qwen3:14b-q4_K_M
```

Save and close the file before continuing.

### 7. Clear cached configuration

```powershell
php artisan config:clear
```

### 8. Generate the application key

Generate a key only if `APP_KEY` is empty, so existing encrypted data and sessions keep their original key.

```powershell
if (Select-String -LiteralPath '.env' -Pattern '^APP_KEY=\s*$' -Quiet) {
    php artisan key:generate
}
```

### 9. Create the databases

Use the MySQL executable included with Laragon. Adjust the version folder if yours differs. These commands use Laragon's default local root account with an empty password; add `--password` to each MySQL command to receive a password prompt if your root account has one.

```powershell
$mysqlBinary = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe'
& $mysqlBinary --user=root --execute="CREATE DATABASE IF NOT EXISTS sales_call_qa_agent CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
& $mysqlBinary --user=root --execute="CREATE DATABASE IF NOT EXISTS sales_call_qa_agent_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 10. Run the migrations

```powershell
php artisan migrate
```

The users migration creates the admin and manager accounts listed below on a fresh database. Use ordinary migrations when updating an existing installation to preserve its recordings and reviews.

### 11. Create the demonstration QA templates

```powershell
php artisan db:seed
```

This publishes the banking demonstration rubric and an illustrative insurance template.

### 12. Download the local AI tools and models

```powershell
powershell -ExecutionPolicy Bypass -File scripts/setup-local-ai.ps1
```

The script checks asset hashes, installs FFmpeg and whisper.cpp inside `tools`, downloads `small.en`, starts Ollama if necessary, and pulls Qwen3. Allow time and disk space for the model downloads. If Ollama is installed elsewhere, pass its executable path:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/setup-local-ai.ps1 -OllamaPath 'D:\Ollama\ollama.exe'
```

### 13. Build the frontend assets

```powershell
npm run build
```

### 14. Start the application and background workers

```powershell
powershell -ExecutionPolicy Bypass -File scripts/start-local.ps1
```

The launcher starts the local web server on port 8015, the `imports` and `audio` queue workers, and the scheduler. It also applies the PHP upload limits needed for bulk uploads. Runtime logs are written to `storage/logs`.

### 15. Open the application and sign in

```powershell
Start-Process 'http://127.0.0.1:8015'
```

The default local accounts are:

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@example.test` | `password` |
| Manager | `manager@example.test` | `password` |

Both passwords are stored as hashes. Public registration is disabled.

## Starting the project later

Start MySQL in Laragon and ensure Ollama is running, then run:

```powershell
Set-Location 'C:\laragon\www\sales-call-qa-agent'
powershell -ExecutionPolicy Bypass -File scripts/start-local.ps1
Start-Process 'http://127.0.0.1:8015'
```

For frontend development, run this in a separate PowerShell terminal while the application is running:

```powershell
Set-Location 'C:\laragon\www\sales-call-qa-agent'
npm run dev
```

After PHP code changes, restart the queue workers so they load the new code:

```powershell
php artisan queue:restart
```

Once the workers have exited after finishing their current job, run the launcher again:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/start-local.ps1
```

## Uploading and reviewing calls

Choose **Upload calls**, select one published QA template and the recording completeness setting, and add recordings or ZIP archives. Each recording receives its own transcription, evaluation, and review history. The batch page shows accepted recordings, skipped entries, failures, and evaluation links.

The default limits are 20 selected sources, 100 MiB per ZIP, 250 MiB combined uploads, 50 recordings, 200 archive entries, and 500 MiB expanded data per batch. Individual recordings must be decodable MP3, WAV, M4A, or OGG files of at most 25 MiB and 15 minutes.

Evaluation processes persisted transcript windows sequentially, starting with a 16K total context allowance. Confirmed model memory failures trigger 8K and then 4K fallback. Smaller contexts cannot resolve memory shortages caused by loading the model itself. Saved progress and transcripts are reused by compatible retries. Review evidence beside the audio player, then record any overrides with a reason.

If processing was interrupted, the scheduler handles recovery. To request recovery manually:

```powershell
php artisan qa:recover
```

Recording retention is disabled by default. Optional manual cleanup is available through `php artisan qa:prune` after configuring `QA_RETENTION_DAYS`; it previews eligible recordings unless `--execute` is supplied.
