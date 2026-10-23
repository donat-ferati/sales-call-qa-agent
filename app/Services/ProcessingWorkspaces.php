<?php

namespace App\Services;

use App\AI\Exceptions\ProcessingException;
use App\Models\CallRecording;
use App\Models\Evaluation;
use DirectoryIterator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class ProcessingWorkspaces
{
    /** @var array<string, resource> */
    private array $leases = [];

    public function __construct(
        private Filesystem $files,
        private Repository $config,
    ) {
    }

    public function create(string $sourcePath): string
    {
        $root = $this->root();
        $this->files->ensureDirectoryExists($root);
        $directory = $root.'/'.Str::uuid();
        $this->files->ensureDirectoryExists($directory);
        $lease = @fopen($directory.'/workspace.lock', 'x+b');

        if ($lease === false)
        {
            throw new ProcessingException('The private processing workspace could not acquire its activity lock. Check available disk space.');
        }

        if (!flock($lease, LOCK_EX | LOCK_NB))
        {
            fclose($lease);
            throw new ProcessingException('The private processing workspace is already in use. Retry transcription.');
        }
        $this->leases[$this->key($directory)] = $lease;

        try
        {
            $owner = $this->owner($sourcePath);
            $manifest = ['version' => 1, 'created_at' => time(), 'owner' => $owner];

            if ($this->files->put($directory.'/workspace.json', json_encode($manifest, JSON_THROW_ON_ERROR)) === false)
            {
                throw new ProcessingException('The private processing workspace could not be attributed safely. Check available disk space.');
            }
        }
        catch (Throwable $exception)
        {
            $this->remove($directory);
            throw $exception;
        }

        return $directory;
    }

    public function remove(string $directory): bool
    {
        $key = $this->key($directory);

        if (isset($this->leases[$key]))
        {
            flock($this->leases[$key], LOCK_UN);
            fclose($this->leases[$key]);
            unset($this->leases[$key]);
        }

        if (!$this->safeDirectory($directory))
        {
            return false;
        }

        $this->files->deleteDirectory($directory);
        clearstatcache(true, $directory);

        return !is_dir($directory);
    }

    public function cleanup(): int
    {
        $root = $this->root();

        if (!is_dir($root))
        {
            return 0;
        }
        $maximum = $this->config->get('qa.processing.cleanup_max_directories');
        $grace = $this->config->get('qa.processing.cleanup_grace_seconds');

        if (!is_int($maximum) || $maximum < 1 || !is_int($grace) || $grace < 1)
        {
            throw new ProcessingException('Processing workspace cleanup requires positive integer limits.');
        }
        $lifetime = max(2400, $this->config->get('qa.audio.probe_timeout') + $this->config->get('qa.audio.decode_timeout') + $this->config->get('qa.whisper.timeout')) + $grace;
        $expiredBefore = time() - $lifetime;
        $removed = 0;
        $inspected = 0;

        foreach (new DirectoryIterator($root) as $entry)
        {
            if ($entry->isDot() || !$entry->isDir() || !$this->safeDirectory($entry->getPathname()))
            {
                continue;
            }

            if (++$inspected > $maximum)
            {
                break;
            }
            $directory = $entry->getPathname();
            $modified = @filemtime($directory);

            if ($modified === false || $modified >= $expiredBefore || $this->locked($directory))
            {
                continue;
            }
            $manifest = $this->manifest($directory);

            if ($manifest !== null && ($manifest['created_at'] >= $expiredBefore || $this->activeOwner($manifest['owner'])))
            {
                continue;
            }

            // Older unowned workspaces have no durable attempt identity; protect every active transcription.
            if (($manifest === null || $manifest['owner'] === null) && Evaluation::where('status', 'transcribing')->exists())
            {
                continue;
            }

            if ($this->remove($directory))
            {
                $removed++;
            }
        }

        return $removed;
    }

    private function owner(string $sourcePath): ?array
    {
        $storageRoot = rtrim(str_replace('\\', '/', Storage::disk('local')->path('')), '/').'/';
        $sourcePath = str_replace('\\', '/', $sourcePath);

        if (!str_starts_with($sourcePath, $storageRoot))
        {
            return null;
        }
        $call = CallRecording::where('path', substr($sourcePath, strlen($storageRoot)))->first(['id']);

        if (!$call)
        {
            return null;
        }
        $evaluation = Evaluation::where('call_recording_id', $call->id)->where('status', 'transcribing')->whereNotNull('attempt_token')->latest('id')->first(['id', 'call_recording_id', 'attempt_token']);

        if (!$evaluation)
        {
            return null;
        }

        return ['evaluation_id' => $evaluation->id, 'call_recording_id' => $call->id, 'attempt_token' => $evaluation->attempt_token];
    }

    private function activeOwner(?array $owner): bool
    {
        return $owner !== null && Evaluation::whereKey($owner['evaluation_id'])->where('call_recording_id', $owner['call_recording_id'])
            ->where('attempt_token', $owner['attempt_token'])->where('status', 'transcribing')->exists();
    }

    private function locked(string $directory): bool
    {
        $path = $directory.'/workspace.lock';

        if (!is_file($path))
        {
            return false;
        }

        if (is_link($path))
        {
            return true;
        }
        $lease = @fopen($path, 'r+b');

        if ($lease === false)
        {
            return true;
        }
        $acquired = flock($lease, LOCK_EX | LOCK_NB);

        if ($acquired)
        {
            flock($lease, LOCK_UN);
        }
        fclose($lease);

        return !$acquired;
    }

    private function manifest(string $directory): ?array
    {
        $path = $directory.'/workspace.json';

        if (!is_file($path) || is_link($path))
        {
            return null;
        }
        $size = @filesize($path);

        if ($size === false || $size > 4096)
        {
            return null;
        }

        try
        {
            $manifest = json_decode($this->files->get($path), true, 8, JSON_THROW_ON_ERROR);
        }
        catch (FileNotFoundException|JsonException)
        {
            return null;
        }

        if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1 || !is_int($manifest['created_at'] ?? null) || !array_key_exists('owner', $manifest))
        {
            return null;
        }
        $owner = $manifest['owner'];

        if ($owner !== null && (!is_array($owner) || !is_int($owner['evaluation_id'] ?? null) || $owner['evaluation_id'] < 1
            || !is_int($owner['call_recording_id'] ?? null) || $owner['call_recording_id'] < 1 || !is_string($owner['attempt_token'] ?? null) || !Str::isUuid($owner['attempt_token'])))
        {
            return null;
        }

        return $manifest;
    }

    private function safeDirectory(string $directory): bool
    {
        if (!Str::isUuid(basename($directory)) || is_link($directory))
        {
            return false;
        }
        $root = realpath($this->root());
        $resolved = realpath($directory);

        if ($root === false || $resolved === false)
        {
            return false;
        }

        return str_replace('\\', '/', $resolved) === str_replace('\\', '/', $root).'/'.basename($directory);
    }

    private function root(): string
    {
        return Storage::disk('local')->path('processing');
    }

    private function key(string $directory): string
    {
        return str_replace('\\', '/', $directory);
    }
}
