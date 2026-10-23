<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CriterionEvidence extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $table = 'criterion_evidence';

    public function segment(): BelongsTo
    {
        return $this->belongsTo(TranscriptSegment::class, 'transcript_segment_id');
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(CriterionResult::class, 'criterion_result_id');
    }
}
