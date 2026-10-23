<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallUploadItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer', 'expanded_bytes' => 'integer', 'archive_index' => 'integer', 'archive_entries' => 'integer'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CallUploadBatch::class, 'call_upload_batch_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('archive_index');
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }
}
