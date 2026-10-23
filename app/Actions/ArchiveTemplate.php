<?php

namespace App\Actions;

use App\Models\QaTemplate;
use Illuminate\Support\Facades\Gate;

final class ArchiveTemplate
{
    public function handle(QaTemplate $template): void
    {
        Gate::authorize('update', $template);

        $template->update(['is_archived' => true]);
    }
}
