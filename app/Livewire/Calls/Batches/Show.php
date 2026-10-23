<?php

namespace App\Livewire\Calls\Batches;

use App\Actions\RetryCallUploadBatch;
use App\Models\CallUploadBatch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Show extends Component
{
    public CallUploadBatch $batch;

    public function mount(CallUploadBatch $batch): void
    {
        Gate::authorize('view', $batch);
        $this->batch = $batch;
    }

    public function retry(RetryCallUploadBatch $retry): void
    {
        Gate::authorize('retry', $this->batch);
        $retry->handle($this->batch);
        session()->flash('status', 'Preparation queued again. Already accepted recordings will not be duplicated.');
    }

    public function render(): View
    {
        Gate::authorize('view', $this->batch);
        $this->batch->refresh()->load(['templateVersion', 'user', 'items.evaluation']);
        $sources = $this->batch->items->whereNull('parent_id')->sortBy('id');
        $accepted = $this->batch->items->whereNotNull('evaluation_id');
        $rejected = $this->batch->items->where('status', 'rejected')->count();
        $skipped = $this->batch->items->where('status', 'skipped')->count();
        $completed = $accepted->filter(fn ($item) => $item->evaluation->status === 'completed')->count();
        $failed = $accepted->filter(fn ($item) => $item->evaluation->status === 'failed')->count();
        $analyzing = $accepted->filter(fn ($item) => in_array($item->evaluation->status, ['queued', 'transcribing', 'evaluating'], true))->count();
        $polling = in_array($this->batch->status, ['queued', 'preparing'], true) || $analyzing > 0;

        return view('livewire.calls.batches.show', compact('sources', 'accepted', 'rejected', 'skipped', 'completed', 'failed', 'analyzing', 'polling'))->layout('components.layouts.app', ['title' => 'Upload batch '.$this->batch->id]);
    }
}
