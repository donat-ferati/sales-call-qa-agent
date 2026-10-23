<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TemplateCriterion extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        $protect = function (self $criterion)
        {
            if ($criterion->exists && $criterion->isDirty('template_version_id'))
            {
                throw new LogicException('Criteria cannot be moved between versions. Create a new draft criterion instead.');
            }

            if (TemplateVersion::findOrFail($criterion->template_version_id)->published_at)
            {
                throw new LogicException('Published criteria are immutable. Edit a new draft instead.');
            }
        };

        static::creating($protect);
        static::updating($protect);
        static::deleting($protect);
    }

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'required' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }
}
