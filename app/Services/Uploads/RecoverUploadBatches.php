<?php

namespace App\Services\Uploads;

use App\Jobs\PrepareCallUploadBatch;
use App\Models\CallUploadBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class RecoverUploadBatches
{
    public function handle(): int
    {
        $count = 0;
        $expiredBefore = now()->subSeconds(config('qa.bulk.prepare_timeout') + 60);

        CallUploadBatch::where(function ($query) use ($expiredBefore)
        {
            $query->where(function ($pending)
            {
                $pending->where('status', 'queued')->where('updated_at', '<', now()->subMinutes(2));
            })->orWhere(function ($running) use ($expiredBefore)
            {
                $running->where('status', 'preparing')->where('started_at', '<', $expiredBefore);
            });
        })->eachById(function ($candidate) use (&$count, $expiredBefore)
        {
            DB::transaction(function () use ($candidate, &$count, $expiredBefore)
            {
                $batch = CallUploadBatch::query()->lockForUpdate()->findOrFail($candidate->id);
                $pending = $batch->status === 'queued' && $batch->updated_at->lessThan(now()->subMinutes(2));
                $expired = $batch->status === 'preparing' && $batch->started_at->lessThan($expiredBefore);

                if (!$pending && !$expired)
                {
                    return;
                }

                $batch->update(['status' => 'queued', 'attempt_token' => null, 'started_at' => null, 'error_message' => null]);
                PrepareCallUploadBatch::dispatch($batch->id)->onQueue('imports')->afterCommit();
                $count++;
            });
        });

        CallUploadBatch::where('status', 'prepared')->whereNotNull('error_message')->eachById(function ($batch)
        {
            if (Storage::disk('local')->deleteDirectory('bulk-uploads/'.$batch->storage_key))
            {
                $batch->items()->update(['stored_path' => null]);
                $batch->update(['error_message' => null]);
            }
        });

        return $count;
    }
}
