<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['criteria_snapshot' => 'array', 'segments_snapshot' => 'array', 'context' => 'array', 'context_tokens' => 'integer', 'plan_revision' => 'integer', 'completed_at' => 'datetime'];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(EvaluationStep::class)->orderBy('id');
    }
}
