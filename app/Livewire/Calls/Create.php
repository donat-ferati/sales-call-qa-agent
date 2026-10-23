<?php

namespace App\Livewire\Calls;

use App\Actions\StartCallUploadBatch;
use App\Models\CallUploadBatch;
use App\Models\QaTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithFileUploads;

class Create extends Component
{
    use WithFileUploads;

    public array $sources = [];

    public ?int $templateId = null;

    public bool $isComplete = true;

    public function mount(): void
    {
        Gate::authorize('create', CallUploadBatch::class);
        $selected = request()->integer('template');

        if ($selected > 0 && QaTemplate::whereKey($selected)->where('is_archived', false)->whereNotNull('current_version_id')->exists())
        {
            $this->templateId = $selected;
        }
    }

    public function removeSource(int $index): void
    {
        unset($this->sources[$index]);
        $this->sources = array_values($this->sources);
        $this->resetValidation('sources');
    }

    public function submitBatch(StartCallUploadBatch $start): void
    {
        Gate::authorize('create', CallUploadBatch::class);
        $this->validate(StartCallUploadBatch::rules());
        $batch = $start->handle(auth()->user(), $this->sources, $this->templateId, $this->isComplete);
        session()->flash('status', 'Files uploaded. Preparation and each recording’s analysis are tracked in this batch.');
        $this->redirectRoute('calls.batches.show', $batch);
    }

    public function render(): View
    {
        Gate::authorize('create', CallUploadBatch::class);
        $templates = QaTemplate::where('is_archived', false)->whereNotNull('current_version_id')->with('currentVersion')->orderBy('name')->get();
        $recentBatches = CallUploadBatch::with('templateVersion')->latest()->limit(8)->get();

        return view('livewire.calls.create', compact('templates', 'recentBatches'))->layout('components.layouts.app', ['title' => 'Upload calls']);
    }
}
