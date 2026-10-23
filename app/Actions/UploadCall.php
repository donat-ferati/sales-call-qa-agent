<?php

namespace App\Actions;

use App\AI\AudioProbe;
use App\AI\Exceptions\ProcessingException;
use App\Jobs\TranscribeCall;
use App\Models\CallRecording;
use App\Models\Evaluation;
use App\Models\QaTemplate;
use App\Models\TemplateVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class UploadCall
{
    public function __construct(private AudioProbe $probe)
    {
    }

    public function handle(User $user, UploadedFile $file, int $templateId, bool $isComplete = true, ?int $publishedVersionId = null, ?string $importKey = null): Evaluation
    {
        Gate::forUser($user)->authorize('create', CallRecording::class);
        Validator::make(['audio' => $file, 'import_key' => $importKey], ['audio' => 'required|file|max:'.config('qa.upload.max_kilobytes').'|mimes:mp3,wav,m4a,ogg', 'import_key' => 'nullable|uuid'])->validate();

        if ($importKey && $existing = $this->existing($user, $importKey, $templateId, $publishedVersionId))
        {
            return $existing;
        }

        try
        {
            $metadata = $this->probe->inspect($file->getRealPath());
        }
        catch (ProcessingException $exception)
        {
            throw ValidationException::withMessages(['audio' => $exception->getMessage()]);
        }

        if ($metadata['duration'] <= 0 || $metadata['duration'] > config('qa.upload.max_duration_seconds'))
        {
            throw ValidationException::withMessages(['audio' => 'Choose an audio recording between 1 second and 15 minutes.']);
        }
        $path = $file->store('calls', 'local');

        if (!is_string($path))
        {
            throw ValidationException::withMessages(['audio' => 'The recording could not be saved to private storage.']);
        }

        try
        {
            $evaluation = DB::transaction(function () use ($user, $file, $templateId, $publishedVersionId, $importKey, $isComplete, $metadata, $path)
            {
                $template = QaTemplate::query()->lockForUpdate()->findOrFail($templateId);

                if ($importKey && $existing = $this->existing($user, $importKey, $templateId, $publishedVersionId))
                {
                    return $existing;
                }

                if ($publishedVersionId === null)
                {
                    if ($template->is_archived || !$template->current_version_id)
                    {
                        throw ValidationException::withMessages(['templateId' => 'Choose an active published template.']);
                    }
                    $versionId = $template->current_version_id;
                }
                else
                {
                    $versionId = TemplateVersion::whereKey($publishedVersionId)->where('qa_template_id', $templateId)->whereNotNull('published_at')->value('id');

                    if ($versionId === null)
                    {
                        throw ValidationException::withMessages(['templateId' => 'The batch template version is no longer available.']);
                    }
                }
                $call = CallRecording::create([
                    'original_filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 250),
                    'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
                    'duration' => $metadata['duration'], 'uploaded_by' => $user->id,
                    'status' => 'queued', 'is_complete' => $isComplete, 'import_key' => $importKey,
                ]);
                $evaluation = $call->evaluations()->create(['template_version_id' => $versionId, 'status' => 'queued']);
                TranscribeCall::dispatch($evaluation->id)->onQueue('audio')->afterCommit();

                return $evaluation;
            });

            if ($evaluation->call->path !== $path)
            {
                Storage::disk('local')->delete($path);
            }

            return $evaluation;
        }
        catch (Throwable $exception)
        {
            // An after-commit queue failure must never remove media already owned by a persisted call.
            try
            {
                if (!CallRecording::where('path', $path)->exists())
                {
                    Storage::disk('local')->delete($path);
                }
            }
            catch (Throwable $cleanupException)
            {
                report($cleanupException);
            }
            throw $exception;
        }
    }

    private function existing(User $user, string $importKey, int $templateId, ?int $publishedVersionId): ?Evaluation
    {
        $call = CallRecording::where('import_key', $importKey)->first();

        if (!$call)
        {
            return null;
        }
        $evaluation = $call->evaluations()->with('templateVersion')->firstOrFail();

        if ($call->uploaded_by !== $user->id || $evaluation->templateVersion->qa_template_id !== $templateId || ($publishedVersionId !== null && $evaluation->template_version_id !== $publishedVersionId))
        {
            throw ValidationException::withMessages(['audio' => 'The import identity conflicts with another recording.']);
        }

        return $evaluation;
    }
}
