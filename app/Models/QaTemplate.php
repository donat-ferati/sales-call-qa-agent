<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QaTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'created_by' => 'integer',
            'current_version_id' => 'integer',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class, 'qa_template_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'current_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
