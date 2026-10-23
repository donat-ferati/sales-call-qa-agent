<?php

namespace App\Livewire\Calls;

use App\Models\Evaluation;
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
    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Evaluation::class);

        $evaluations = Evaluation::with(['call', 'templateVersion'])
            ->when($this->search !== '', fn ($query) => $query->whereHas('call', fn ($call) => $call->where('original_filename', 'like', '%'.$this->search.'%')))
            ->when(in_array($this->status, ['queued', 'transcribing', 'evaluating', 'completed', 'failed'], true), fn ($query) => $query->where('status', $this->status))
            ->latest()->paginate(15);

        return view('livewire.calls.index', compact('evaluations'))->layout('components.layouts.app', ['title' => 'Call library']);
    }
}
