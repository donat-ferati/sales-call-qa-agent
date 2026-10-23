<?php

namespace App\Actions;

use App\Models\QaTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DuplicateTemplate
{
    public function __construct(private SaveTemplate $save)
    {
    }

    public function handle(QaTemplate $template, User $user): QaTemplate
    {
        Gate::forUser($user)->authorize('view', $template);

        $version = $template->versions()->latest('number')->firstOrFail();

        return $this->save->handle($user, [
            'name' => mb_substr($template->name, 0, 140).' (copy)',
            'description' => $template->description,
            'criteria' => $version->criteria->map(fn ($criterion) => $criterion->only(['title', 'question', 'instructions', 'pass_standard', 'applicability', 'weight', 'required']))->all(),
        ]);
    }
}
