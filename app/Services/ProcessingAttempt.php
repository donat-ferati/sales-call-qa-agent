<?php

namespace App\Services;

use App\Models\Evaluation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProcessingAttempt
{
    public function claim(int $id, string $stage): ?string
    {
        return DB::transaction(function () use ($id, $stage)
        {
            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($id);

            if ($evaluation->status === 'completed' || $evaluation->results()->exists())
            {
                return null;
            }

            if (in_array($evaluation->status, ['transcribing', 'evaluating'], true) && $evaluation->started_at?->greaterThan(now()->subMinutes(40)))
            {
                return null;
            }
            if ($stage === 'transcribing')
            {
                if ($evaluation->transcription_attempts >= 3)
                {
                    return null;
                }
                $evaluation->transcription_attempts++;
            }
            $token = (string) Str::uuid();
            $evaluation->update(['status' => $stage, 'attempt_token' => $token, 'started_at' => now(), 'error_message' => null]);
            $evaluation->call->update(['status' => $stage, 'error_message' => null]);

            return $token;
        });
    }

    public function fail(int $id, string $token, string $message): void
    {
        DB::transaction(function () use ($id, $token, $message)
        {
            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($id);

            if ($evaluation->attempt_token !== $token || $evaluation->status === 'completed')
            {
                return;
            }
            $evaluation->update(['status' => 'failed', 'error_message' => $message, 'attempt_token' => null]);
            $evaluation->call->update(['status' => 'failed', 'error_message' => $message]);
        });
    }
}
