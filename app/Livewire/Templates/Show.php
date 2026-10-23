<?php

namespace App\Livewire\Templates;

use App\Actions\DuplicateTemplate;
use App\Actions\PublishTemplate;
use App\Models\QaTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    public QaTemplate $template;

    #[Locked]
    public ?int $versionId = null;

    public function mount(QaTemplate $template): void
    {
        Gate::authorize('view', $template);
        $this->template = $template;
        $requestedVersion = request()->query('version');

        if ($requestedVersion !== null)
        {
            $id = filter_var($requestedVersion, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_if($id === false, 404);

            $this->versionId = $template->versions()->findOrFail($id)->id;

            return;
        }

        $this->versionId = $template->versions()->orderByDesc('number')->first(['id'])?->id;
    }

    public function publish(PublishTemplate $publish): void
    {
        Gate::authorize('update', $this->template);

        DB::transaction(function () use ($publish)
        {
            $template = QaTemplate::query()->lockForUpdate()->findOrFail($this->template->id);
            $draft = $template->versions()->whereNull('published_at')->first();

            if (!$draft || $draft->id !== $this->versionId)
            {
                throw ValidationException::withMessages(['version' => 'Open the latest draft before publishing. The version you previewed is unchanged.']);
            }

            $publish->handle($template);
        });
        $this->template->refresh();

        session()->flash('status', 'Template published and available for new calls.');
    }

    public function duplicate(DuplicateTemplate $duplicate): void
    {
        Gate::authorize('create', QaTemplate::class);
        Gate::authorize('view', $this->template);

        $copy = DB::transaction(function () use ($duplicate)
        {
            $template = QaTemplate::query()->lockForUpdate()->findOrFail($this->template->id);
            $latest = $template->versions()->orderByDesc('number')->first(['id']);

            if ($latest?->id !== $this->versionId)
            {
                throw ValidationException::withMessages(['version' => 'Open the latest version before duplicating this template.']);
            }

            return $duplicate->handle($template, auth()->user());
        });
        $this->redirectRoute('templates.edit', $copy);
    }

    public function render(): View
    {
        Gate::authorize('view', $this->template);
        $this->template->refresh();

        $versions = $this->template->versions()->orderByDesc('number')->get();
        $version = $this->versionId === null ? null : $this->template->versions()->with('criteria')->findOrFail($this->versionId);
        $isLatest = $version?->id === $versions->first()?->id;
        $publishedVersion = $versions->firstWhere('id', $this->template->current_version_id);

        return view('livewire.templates.show', compact('version', 'versions', 'isLatest', 'publishedVersion'))->layout('components.layouts.app', ['title' => $version ? $version->name : $this->template->name]);
    }
}
