<?php

namespace App\Actions;

use App\Jobs\PrepareCallUploadBatch;
use App\Models\CallUploadBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RetryCallUploadBatch
{
    public function handle(CallUploadBatch $batch): void
    {
        Gate::authorize('retry', $batch);

        DB::transaction(function () use ($batch)
        {
            $batch = CallUploadBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($batch->status !== 'failed')
            {
                throw ValidationException::withMessages(['retry' => 'Only interrupted upload preparation can be retried. Open an evaluation to retry AI processing.']);
            }

            $batch->update(['status' => 'queued', 'attempt_token' => null, 'started_at' => null, 'completed_at' => null, 'error_message' => null]);
            PrepareCallUploadBatch::dispatch($batch->id)->onQueue('imports')->afterCommit();
        });
    }
}
