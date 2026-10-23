<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptSegment extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'start' => 'float',
            'end' => 'float',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(CallRecording::class, 'call_recording_id');
    }
}
