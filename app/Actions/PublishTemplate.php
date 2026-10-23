<?php

namespace App\Actions;

use App\Models\QaTemplate;
use App\Models\TemplateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class PublishTemplate
{
    public function handle(QaTemplate $template): TemplateVersion
    {
        Gate::authorize('update', $template);

        return DB::transaction(function () use ($template)
        {
            $template = QaTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $draft = $template->versions()->whereNull('published_at')->withCount('criteria')->first();

            if ($template->is_archived || !$draft || !$draft->criteria_count)
            {
                throw ValidationException::withMessages(['template' => 'Publish requires an active template with a saved draft and at least one criterion.']);
            }

            $draft->update(['published_at' => now()]);
            $template->update(['current_version_id' => $draft->id]);

            return $draft;
        });
    }
}
