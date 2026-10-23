<?php

namespace App\Jobs;

use App\Services\WindowedEvaluationWorkflow;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class EvaluateCall implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    // Lock contention is not a model attempt. Actual attempts are bounded on each persisted step.
    public int $tries = 0;

    public int $uniqueFor = 2400;

    public function __construct(public int $evaluationId)
    {
    }

    public function uniqueId(): string
    {
        return 'evaluate-call:'.$this->evaluationId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('local-ai'))->shared()->releaseAfter(10)->expireAfter(2400)];
    }

    public function handle(WindowedEvaluationWorkflow $workflow): void
    {
        $workflow->advance($this->evaluationId);
    }
}
