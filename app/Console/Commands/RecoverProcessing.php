<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateCall;
use App\Jobs\TranscribeCall;
use App\Models\Evaluation;
use App\Models\EvaluationRun;
use App\Services\Uploads\RecoverUploadBatches;
use App\Services\WindowedEvaluationWorkflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RecoverProcessing extends Command
{
    protected $signature = 'qa:recover';

    protected $description = 'Recover interrupted evaluation steps, upload imports, and abandoned transcription attempts.';

    public function handle(WindowedEvaluationWorkflow $workflow, RecoverUploadBatches $uploads): int
    {
        $count = 0;
        Evaluation::whereIn('status', ['queued', 'evaluating'])->whereIn('id', EvaluationRun::select('evaluation_id')->where('status', 'running'))->eachById(function ($evaluation) use ($workflow, &$count)
        {
            $count += (int) $workflow->recover($evaluation);
        });
        Evaluation::where('status', 'queued')->whereNotIn('id', EvaluationRun::select('evaluation_id'))->eachById(function ($evaluation) use (&$count)
        {
            if ($evaluation->call->segments()->exists())
            {
                EvaluateCall::dispatch($evaluation->id)->onQueue('audio')->afterCommit();
            }
            else
            {
                TranscribeCall::dispatch($evaluation->id)->onQueue('audio')->afterCommit();
            }
            $count++;
        });
        Evaluation::whereIn('status', ['transcribing', 'evaluating'])->where('started_at', '<', now()->subMinutes(40))->whereNotIn('id', EvaluationRun::select('evaluation_id')->where('status', 'running'))->eachById(function ($candidate) use (&$count)
        {
            DB::transaction(function () use ($candidate, &$count)
            {
                $evaluation = Evaluation::query()->lockForUpdate()->find($candidate->id);

                if (!$evaluation || !in_array($evaluation->status, ['transcribing', 'evaluating'], true) || $evaluation->started_at->greaterThan(now()->subMinutes(40)))
                {
                    return;
                }
                $evaluation->update(['status' => 'failed', 'attempt_token' => null, 'error_message' => 'Processing was interrupted. Retry to resume from the last saved stage.']);
                $evaluation->call->update(['status' => 'failed', 'error_message' => $evaluation->error_message]);
                $count++;
            });
        });
        $imports = $uploads->handle();
        $this->info('Recovered '.$count.' evaluation/transcription tasks and '.$imports.' upload batches. Completed transcripts and checkpoints were retained.');

        return self::SUCCESS;
    }
}
