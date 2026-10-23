<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallUploadBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_complete' => 'boolean', 'source_count' => 'integer', 'source_bytes' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(QaTemplate::class, 'qa_template_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CallUploadItem::class);
    }

    public function sources(): HasMany
    {
        return $this->items()->whereNull('parent_id');
    }
}
