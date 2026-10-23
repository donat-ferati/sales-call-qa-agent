<?php

namespace App\Livewire\Templates;

use App\Actions\ArchiveTemplate;
use App\Actions\DuplicateTemplate;
use App\Models\QaTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'active';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function duplicate(int $id, DuplicateTemplate $duplicate): void
    {
        $template = QaTemplate::findOrFail($id);

        Gate::authorize('view', $template);
        Gate::authorize('create', QaTemplate::class);

        $copy = $duplicate->handle($template, auth()->user());

        session()->flash('status', 'Template duplicated as a new draft.');

        $this->redirectRoute('templates.edit', $copy);
    }

    public function archive(int $id, ArchiveTemplate $archive): void
    {
        $template = QaTemplate::findOrFail($id);

        Gate::authorize('update', $template);

        $archive->handle($template);

        session()->flash('status', 'Template archived. Existing evaluations remain available.');
    }

    public function render(): View
    {
        Gate::authorize('viewAny', QaTemplate::class);

        $templates = QaTemplate::query()->with(['currentVersion', 'creator'])->withCount('versions')
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'))
            ->when($this->status === 'active', fn ($query) => $query->where('is_archived', false))
            ->when($this->status === 'archived', fn ($query) => $query->where('is_archived', true))
            ->latest()->paginate(12);

        return view('livewire.templates.index', compact('templates'))->layout('components.layouts.app', ['title' => 'QA templates']);
    }
}
