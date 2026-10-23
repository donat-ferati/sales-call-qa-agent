<?php

namespace App\Jobs;

use App\Models\CallUploadBatch;
use App\Services\Uploads\PrepareUploadBatch;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class PrepareCallUploadBatch implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 2400;

    public function __construct(public int $batchId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('call-upload-batch:'.$this->batchId))->releaseAfter(60)->expireAfter(config('qa.bulk.prepare_timeout') + 60)];
    }

    public function handle(PrepareUploadBatch $prepare): void
    {
        $prepare->handle($this->batchId);
    }

    public function failed(?Throwable $exception): void
    {
        CallUploadBatch::whereKey($this->batchId)->whereIn('status', ['queued', 'failed'])->update([
            'status' => 'failed', 'attempt_token' => null,
            'error_message' => 'Upload preparation could not finish. Retry to resume; accepted recordings remain available.',
        ]);
    }
}
