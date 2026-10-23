<?php

namespace App\Actions;

use App\Jobs\PrepareCallUploadBatch;
use App\Models\CallUploadBatch;
use App\Models\QaTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StartCallUploadBatch
{
    public static function rules(): array
    {
        return [
            'sources' => ['required', 'array', 'min:1', 'max:'.config('qa.bulk.max_sources')],
            'sources.*' => ['required', 'file', 'max:'.config('qa.bulk.max_source_kilobytes')],
            'templateId' => ['required', 'integer', 'exists:qa_templates,id'],
            'isComplete' => ['required', 'boolean'],
        ];
    }

    /** @param array<UploadedFile> $sources */
    public function handle(User $user, array $sources, int $templateId, bool $isComplete): CallUploadBatch
    {
        Gate::forUser($user)->authorize('create', CallUploadBatch::class);
        Validator::make(compact('sources', 'templateId', 'isComplete'), self::rules())->validate();
        $bytes = 0;

        foreach ($sources as $source)
        {
            if (!$source instanceof UploadedFile)
            {
                throw ValidationException::withMessages(['sources' => 'Select recordings or ZIP files to upload.']);
            }

            $bytes += $source->getSize();
        }

        if ($bytes > config('qa.bulk.max_total_bytes'))
        {
            throw ValidationException::withMessages(['sources' => 'The selected files exceed the 250 MiB batch limit. Choose a smaller selection.']);
        }

        $storageKey = (string) Str::uuid();
        $staged = [];

        try
        {
            foreach ($sources as $source)
            {
                $extension = strtolower($source->getClientOriginalExtension());
                $extension = in_array($extension, [...config('qa.upload.extensions'), 'zip'], true) ? $extension : 'bin';
                $path = $source->storeAs('bulk-uploads/'.$storageKey.'/sources', Str::uuid().'.'.$extension, 'local');

                if ($path === false)
                {
                    throw ValidationException::withMessages(['sources' => 'A selected file could not be saved. Check available disk space and try again.']);
                }

                $staged[] = [
                    'idempotency_key' => (string) Str::uuid(), 'kind' => $extension === 'zip' ? 'zip' : 'audio',
                    'original_name' => mb_substr(basename(str_replace(['\\', "\0"], ['/', ''], $source->getClientOriginalName())), 0, 250),
                    'stored_path' => $path, 'size' => $source->getSize(),
                ];
            }

            return DB::transaction(function () use ($user, $templateId, $isComplete, $storageKey, $staged, $bytes)
            {
                $template = QaTemplate::query()->lockForUpdate()->findOrFail($templateId);
                $version = $template->currentVersion;

                if ($template->is_archived || !$version || !$version->published_at)
                {
                    throw ValidationException::withMessages(['templateId' => 'Choose an active published QA template.']);
                }

                $batch = CallUploadBatch::create([
                    'storage_key' => $storageKey, 'user_id' => $user->id, 'qa_template_id' => $template->id,
                    'template_version_id' => $version->id, 'is_complete' => $isComplete,
                    'source_count' => count($staged), 'source_bytes' => $bytes, 'status' => 'queued',
                ]);

                foreach ($staged as $source)
                {
                    $batch->items()->create($source);
                }

                PrepareCallUploadBatch::dispatch($batch->id)->onQueue('imports')->afterCommit();

                return $batch;
            });
        }
        catch (Throwable $exception)
        {
            // Once the manifest committed, recovery can safely redispatch a lost job.
            if (!CallUploadBatch::where('storage_key', $storageKey)->exists())
            {
                Storage::disk('local')->deleteDirectory('bulk-uploads/'.$storageKey);
            }

            throw $exception;
        }
    }
}
