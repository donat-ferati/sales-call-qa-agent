<?php

namespace App\Actions;

use App\Jobs\EvaluateCall;
use App\Jobs\TranscribeCall;
use App\Models\Evaluation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RetryEvaluation
{
    public function handle(Evaluation $evaluation): void
    {
        Gate::authorize('review', $evaluation);

        DB::transaction(function () use ($evaluation)
        {
            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($evaluation->id);

            if ($evaluation->status !== 'failed' || $evaluation->results()->exists())
            {
                throw ValidationException::withMessages(['retry' => 'Only failed, unreviewed processing can be retried.']);
            }

            $hasTranscript = $evaluation->call->segments()->exists();
            $evaluation->update(['status' => 'queued', 'error_message' => null, 'attempt_token' => null, 'transcription_attempts' => $hasTranscript ? $evaluation->transcription_attempts : 0]);
            $evaluation->call->update(['status' => $hasTranscript ? 'transcript_ready' : 'queued', 'error_message' => null]);

            if ($hasTranscript)
            {
                EvaluateCall::dispatch($evaluation->id)->onQueue('audio')->afterCommit();

                return;
            }

            TranscribeCall::dispatch($evaluation->id)->onQueue('audio')->afterCommit();
        });
    }
}
