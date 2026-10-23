<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Evaluation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transcription_attempts' => 'integer',
            'ai_score' => 'decimal:2',
            'final_score' => 'decimal:2',
            'ai_coverage' => 'decimal:2',
            'final_coverage' => 'decimal:2',
            'ai_provisional_score' => 'decimal:2',
            'final_provisional_score' => 'decimal:2',
            'provider_metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(CallRecording::class, 'call_recording_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(CriterionResult::class, 'evaluation_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(ScoreOverride::class, 'evaluation_id');
    }
}
