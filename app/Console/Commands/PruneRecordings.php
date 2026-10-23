<?php

namespace App\Console\Commands;

use App\Models\CallRecording;
use App\Models\Evaluation;
use App\Services\ProcessingWorkspaces;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class PruneRecordings extends Command
{
    protected $signature = 'qa:prune {--execute : Permanently delete expired recordings and dependent review history}';

    protected $description = 'Preview or execute opt-in recording retention; disabled unless QA_RETENTION_DAYS is positive.';

    public function handle(ProcessingWorkspaces $workspaces): int
    {
        $days = config('qa.retention_days');

        if (!$days || $days < 1)
        {
            $this->info('Retention is disabled. Configure QA_RETENTION_DAYS to opt in.');

            return self::SUCCESS;
        }

        $query = CallRecording::where('created_at', '<', now()->subDays($days))->whereIn('status', ['completed', 'failed']);
        $this->info($query->count().' recordings match the retention policy.');

        if ($this->option('execute'))
        {
            $failed = false;
            $query->eachById(function ($candidate) use ($days, &$failed)
            {
                try
                {
                    DB::transaction(function () use ($candidate, $days)
                    {
                        // Processing claims lock evaluations before their recording; retain that order.
                        Evaluation::where('call_recording_id', $candidate->id)->orderBy('id')->lockForUpdate()->get();
                        $call = CallRecording::query()->lockForUpdate()->find($candidate->id);

                        if (!$call || !in_array($call->status, ['completed', 'failed'], true) || $call->created_at->greaterThanOrEqualTo(now()->subDays($days)))
                        {
                            return;
                        }

                        if (!Storage::disk('local')->delete($call->path))
                        {
                            throw new \RuntimeException('Private audio could not be deleted.');
                        }
                        $call->delete();
                    });
                }
                catch (Throwable)
                {
                    $failed = true;
                    $this->error('Recording '.$candidate->id.' was not pruned. Check private storage and retry; its database history is retained.');
                }
            });

            $removed = $workspaces->cleanup();
            $this->info('Removed '.$removed.' abandoned processing workspaces.');

            if ($failed)
            {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
