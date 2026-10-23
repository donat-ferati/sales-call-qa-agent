<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class TemplateVersion extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        $protect = function (self $version)
        {
            if ($version->getOriginal('published_at'))
            {
                throw new LogicException('Published template versions are immutable. Create a new draft instead.');
            }
        };

        static::updating($protect);
        static::deleting($protect);
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(QaTemplate::class, 'qa_template_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(TemplateCriterion::class, 'template_version_id')->orderBy('display_order');
    }
}
