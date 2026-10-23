<?php

namespace App\Jobs;

use App\AI\Contracts\TranscriptionProvider;
use App\AI\Exceptions\ProcessingException;
use App\Models\Evaluation;
use App\Services\ProcessingAttempt;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class TranscribeCall implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $uniqueFor = 2400;

    public function __construct(public int $evaluationId)
    {
    }

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function uniqueId(): string
    {
        return 'transcribe-call:'.$this->evaluationId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('local-ai'))->shared()->releaseAfter(10)->expireAfter(2400)];
    }

    public function handle(TranscriptionProvider $provider, ProcessingAttempt $attempts): void
    {
        $evaluation = Evaluation::findOrFail($this->evaluationId);

        if ($evaluation->status === 'completed')
        {
            return;
        }

        if ($evaluation->call->segments()->exists())
        {
            EvaluateCall::dispatch($evaluation->id)->onQueue('audio');

            return;
        }

        if (!$token = $attempts->claim($evaluation->id, 'transcribing'))
        {
            return;
        }

        try
        {
            $transcript = $provider->transcribe(Storage::disk('local')->path($evaluation->call->path));

            DB::transaction(function () use ($transcript, $token)
            {
                $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($this->evaluationId);

                if ($evaluation->attempt_token !== $token)
                {
                    return;
                }

                if (!$transcript->segments)
                {
                    throw new RuntimeException('No audible speech was found.');
                }

                foreach ($transcript->segments as $segment)
                {
                    if ($segment['start'] < 0 || $segment['end'] <= $segment['start'] || $segment['end'] > $evaluation->call->duration + 0.1 || trim($segment['text']) === '')
                    {
                        throw new RuntimeException('Transcription returned invalid segment boundaries.');
                    }
                    $evaluation->call->segments()->create($segment);
                }
                $evaluation->call->update(['status' => 'transcript_ready', 'transcription_metadata' => $transcript->metadata + ['language' => $transcript->language]]);
                $evaluation->update(['status' => 'queued', 'attempt_token' => null]);
                EvaluateCall::dispatch($evaluation->id)->onQueue('audio')->afterCommit();
            });
        }
        catch (Throwable $exception)
        {
            $message = $exception instanceof ProcessingException ? $exception->getMessage() : 'Transcription failed. Check the recording, FFmpeg and Whisper configuration, then retry.';
            $attempts->fail($this->evaluationId, $token, $message);
            throw new RuntimeException($message);
        }
    }
}
