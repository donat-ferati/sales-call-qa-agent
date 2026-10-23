<?php

namespace App\Services\Uploads;

use App\Actions\UploadCall;
use App\Models\CallUploadBatch;
use App\Models\CallUploadItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PrepareUploadBatch
{
    public function __construct(
        private UploadCall $upload,
        private SecureZipRecordings $archives,
    ) {
    }

    public function handle(int $batchId): void
    {
        $token = (string) Str::uuid();
        $batch = DB::transaction(function () use ($batchId, $token)
        {
            $batch = CallUploadBatch::query()->lockForUpdate()->findOrFail($batchId);

            if ($batch->status === 'prepared' || ($batch->status === 'preparing' && $batch->started_at->greaterThan(now()->subSeconds(config('qa.bulk.prepare_timeout') + 60))))
            {
                return null;
            }

            $batch->update(['status' => 'preparing', 'attempt_token' => $token, 'started_at' => now(), 'completed_at' => null, 'error_message' => null]);

            return $batch;
        });

        if (!$batch)
        {
            return;
        }

        $batch->load('user');
        $deadline = $batch->started_at->getTimestamp() + config('qa.bulk.prepare_timeout');

        try
        {
            foreach ($batch->sources()->orderBy('id')->get() as $source)
            {
                $this->checkAttempt($batch, $token, $deadline);

                if (in_array($source->status, ['queued', 'rejected', 'expanded'], true))
                {
                    continue;
                }

                if ($source->kind === 'zip')
                {
                    $this->prepareArchive($batch, $source, $token, $deadline);
                }
                else
                {
                    $source->update(['expanded_bytes' => $source->size]);
                    $this->importRecording($batch, $source, $token, $deadline);
                }

                $batch->touch();
            }

            $this->checkAttempt($batch, $token, $deadline);
            $batch->update(['status' => 'prepared', 'attempt_token' => null, 'completed_at' => now(), 'error_message' => null]);
            $this->cleanTerminalFiles($batch);
        }
        catch (Throwable $exception)
        {
            CallUploadBatch::whereKey($batch->id)->where('attempt_token', $token)->update([
                'status' => 'failed', 'attempt_token' => null,
                'error_message' => $exception instanceof PreparationDeadlineExceeded ? $exception->getMessage() : 'Upload preparation was interrupted. Retry to resume; accepted recordings will not be duplicated.',
            ]);

            throw $exception;
        }
    }

    private function prepareArchive(CallUploadBatch $batch, CallUploadItem $source, string $token, int $deadline): void
    {
        $source->update(['status' => 'expanding', 'error_message' => null]);
        $directory = 'bulk-uploads/'.$batch->storage_key.'/attempts/'.$token.'/'.$source->id;

        try
        {
            if (!$source->stored_path || !Storage::disk('local')->exists($source->stored_path))
            {
                throw new ArchiveUploadException('The staged ZIP is unavailable. Upload this archive again in a new batch.');
            }

            $otherSources = $batch->sources()->where('id', '!=', $source->id)->where('status', '!=', 'rejected')->get();
            $usedBytes = $otherSources->sum(fn ($other) => $other->kind === 'audio' ? $other->size : ($other->expanded_bytes ?? 0));
            $usedEntries = $otherSources->sum('archive_entries');
            $usedRecordings = $batch->items()->where('kind', 'audio')->where(function ($query) use ($source)
            {
                $query->whereNull('parent_id')->orWhere('parent_id', '!=', $source->id);
            })->count();

            $manifest = $this->archives->prepare(
                Storage::disk('local')->path($source->stored_path), $directory,
                config('qa.bulk.max_recordings') - $usedRecordings,
                config('qa.bulk.max_archive_entries') - $usedEntries,
                config('qa.bulk.max_expanded_bytes') - $usedBytes, $deadline,
            );
            $this->checkAttempt($batch, $token, $deadline);
            $source->update(['expanded_bytes' => $manifest['expanded_bytes'], 'archive_entries' => $manifest['archive_entries']]);

            foreach ($manifest['entries'] as $entry)
            {
                $this->checkAttempt($batch, $token, $deadline);
                $item = $source->children()->firstOrCreate(['archive_index' => $entry['index']], [
                    'call_upload_batch_id' => $batch->id, 'idempotency_key' => (string) Str::uuid(),
                    'kind' => $entry['kind'], 'original_name' => mb_substr(basename($entry['name']), 0, 250), 'entry_path' => $entry['name'],
                    'size' => $entry['size'], 'status' => $entry['status'], 'error_message' => $entry['error'],
                ]);

                if ($item->kind !== 'audio' || in_array($item->status, ['queued', 'rejected'], true))
                {
                    continue;
                }

                $item->update(['stored_path' => $entry['path']]);
                $this->importRecording($batch, $item, $token, $deadline);
                $batch->touch();
            }

            $source->update(['status' => 'expanded', 'error_message' => null]);
        }
        catch (ArchiveUploadException $exception)
        {
            $this->checkAttempt($batch, $token, $deadline);
            $source->update(['status' => 'rejected', 'expanded_bytes' => null, 'archive_entries' => 0, 'error_message' => $exception->getMessage()]);
        }
        finally
        {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }

    private function importRecording(CallUploadBatch $batch, CallUploadItem $item, string $token, int $deadline): void
    {
        $this->checkAttempt($batch, $token, $deadline);
        $item->update(['status' => 'validating', 'error_message' => null]);

        try
        {
            if (!$item->stored_path || !Storage::disk('local')->exists($item->stored_path))
            {
                throw ValidationException::withMessages(['audio' => 'This staged recording is unavailable. Upload it again in a new batch.']);
            }

            if ($item->size > config('qa.upload.max_kilobytes') * 1024)
            {
                throw ValidationException::withMessages(['audio' => 'This recording exceeds 25 MiB. Choose a smaller audio file.']);
            }

            $file = new UploadedFile(Storage::disk('local')->path($item->stored_path), $item->original_name, null, UPLOAD_ERR_OK, true);
            $evaluation = $this->upload->handle($batch->user, $file, $batch->qa_template_id, $batch->is_complete, $batch->template_version_id, $item->idempotency_key);
            $this->checkAttempt($batch, $token, $deadline);
            $item->update(['status' => 'queued', 'evaluation_id' => $evaluation->id, 'error_message' => null]);
        }
        catch (ValidationException $exception)
        {
            $this->checkAttempt($batch, $token, $deadline);
            $item->update(['status' => 'rejected', 'error_message' => $exception->getMessage()]);
        }
    }

    private function checkAttempt(CallUploadBatch $batch, string $token, int $deadline): void
    {
        if (time() >= $deadline || !CallUploadBatch::whereKey($batch->id)->where('attempt_token', $token)->where('status', 'preparing')->exists())
        {
            throw new PreparationDeadlineExceeded('Upload preparation reached its time limit or was superseded. Retry to resume without duplicating accepted recordings.');
        }
    }

    private function cleanTerminalFiles(CallUploadBatch $batch): void
    {
        if (Storage::disk('local')->deleteDirectory('bulk-uploads/'.$batch->storage_key))
        {
            $batch->items()->update(['stored_path' => null]);

            return;
        }

        $batch->update(['error_message' => 'Recordings were prepared, but temporary-file cleanup needs attention. Run upload recovery to retry cleanup.']);
    }
}
