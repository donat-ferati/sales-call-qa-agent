<?php

namespace App\Livewire\Evaluations;

use App\Actions\MarkReviewed;
use App\Actions\OverrideCriterion;
use App\Actions\RetryEvaluation;
use App\Enums\Decision;
use App\Models\Evaluation;
use App\Services\WindowedEvaluationWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Show extends Component
{
    public Evaluation $evaluation;

    public ?int $editingResultId = null;

    public string $newDecision = '';

    public string $reason = '';

    public int $expectedRevision = 0;

    public function mount(Evaluation $evaluation): void
    {
        Gate::authorize('view', $evaluation);
        $this->evaluation = $evaluation;
    }

    public function startOverride(int $id): void
    {
        Gate::authorize('review', $this->evaluation);

        $result = $this->evaluation->results()->findOrFail($id);

        $this->editingResultId = $result->id;
        $this->newDecision = $result->final_decision;
        $this->expectedRevision = $result->revision;
        $this->reason = '';

        $this->resetValidation();
    }

    public function cancelOverride(): void
    {
        $this->reset('editingResultId', 'newDecision', 'reason', 'expectedRevision');
        $this->resetValidation();
    }

    public function saveOverride(OverrideCriterion $override): void
    {
        Gate::authorize('review', $this->evaluation);

        $this->validate([
            'editingResultId' => ['required', 'integer'],
            'newDecision' => ['required', Rule::enum(Decision::class)],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $result = $this->evaluation->results()->findOrFail($this->editingResultId);
        $override->handle($result, auth()->user(), $this->newDecision, $this->reason, $this->expectedRevision);
        $this->cancelOverride();

        session()->flash('status', 'Review decision saved. The original AI judgment is preserved.');
    }

    public function markReviewed(MarkReviewed $review): void
    {
        Gate::authorize('review', $this->evaluation);

        $review->handle($this->evaluation, auth()->user());

        session()->flash('status', 'Review completed.');
    }

    public function retry(RetryEvaluation $retry): void
    {
        Gate::authorize('review', $this->evaluation);

        $retry->handle($this->evaluation);

        session()->flash('status', 'Processing queued again. An existing transcript will be reused.');
    }

    public function render(WindowedEvaluationWorkflow $workflow): View
    {
        Gate::authorize('view', $this->evaluation);

        $this->evaluation->refresh()->load([
            'call.segments', 'templateVersion', 'reviewer',
            'results.criterion', 'results.evidence.segment', 'results.reviewer', 'results.overrides.user',
        ]);

        $results = $this->evaluation->results->sortBy(fn ($result) => $result->criterion->display_order);
        $segments = $this->evaluation->call->segments->sortBy('sequence');
        $processingProgress = $workflow->progress($this->evaluation);
        $decisions = Decision::cases();
        $unresolved = $results->where('final_decision', Decision::InsufficientEvidence->value)->count();

        return view('livewire.evaluations.show', compact('results', 'segments', 'decisions', 'unresolved', 'processingProgress'))->layout('components.layouts.app', ['title' => $this->evaluation->call->original_filename]);
    }
}
