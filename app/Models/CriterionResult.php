<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CriterionResult extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class, 'evaluation_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(TemplateCriterion::class, 'template_criterion_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(CriterionEvidence::class, 'criterion_result_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(ScoreOverride::class, 'criterion_result_id');
    }
}
