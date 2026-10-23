<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallRecording extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'duration' => 'float',
            'is_complete' => 'boolean',
            'transcription_metadata' => 'array',
        ];
    }

    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptSegment::class, 'call_recording_id')->orderBy('sequence');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class, 'call_recording_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
