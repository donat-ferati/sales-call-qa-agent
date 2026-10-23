<?php

namespace App\Livewire\Templates;

use App\Actions\DuplicateTemplate;
use App\Actions\PublishTemplate;
use App\Models\QaTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Show extends Component
{
    public QaTemplate $template;

    public function mount(QaTemplate $template): void
    {
        Gate::authorize('view', $template);
        $this->template = $template;
    }

    public function publish(PublishTemplate $publish): void
    {
        Gate::authorize('update', $this->template);

        $publish->handle($this->template);
        $this->template->refresh();

        session()->flash('status', 'Template published and available for new calls.');
    }

    public function duplicate(DuplicateTemplate $duplicate): void
    {
        Gate::authorize('create', QaTemplate::class);
        Gate::authorize('view', $this->template);

        $copy = $duplicate->handle($this->template, auth()->user());
        $this->redirectRoute('templates.edit', $copy);
    }

    public function render(): View
    {
        Gate::authorize('view', $this->template);

        $versions = $this->template->versions()->orderByDesc('number')->get();
        $version = $this->template->versions()->orderByDesc('number')->with('criteria')->first();

        return view('livewire.templates.show', compact('version', 'versions'))->layout('components.layouts.app', ['title' => $this->template->name]);
    }
}
