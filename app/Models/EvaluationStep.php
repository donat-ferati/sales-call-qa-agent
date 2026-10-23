<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EvaluationStep extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $step)
        {
            if ($step->getRawOriginal('status') === 'completed')
            {
                throw new LogicException('Completed evaluation checkpoints are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['criterion_ids' => 'array', 'payload' => 'array', 'output' => 'array', 'context_tokens' => 'integer', 'plan_revision' => 'integer', 'attempts' => 'integer', 'started_at' => 'datetime', 'next_attempt_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EvaluationRun::class, 'evaluation_run_id');
    }
}
