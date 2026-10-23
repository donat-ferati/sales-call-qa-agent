<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScoreOverride extends Model
{
    protected $guarded = [];

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        $protect = fn () => throw new \LogicException('Review history is append-only.');
        static::updating($protect);
        static::deleting($protect);
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(CriterionResult::class, 'criterion_result_id');
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class, 'evaluation_id');
    }
}
