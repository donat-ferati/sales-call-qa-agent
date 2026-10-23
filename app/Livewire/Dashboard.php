<?php

namespace App\Livewire;

use App\Models\CallRecording;
use App\Models\Evaluation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Dashboard extends Component
{
    public function render(): View
    {
        Gate::authorize('viewAny', CallRecording::class);
        $totals = [
            'calls' => CallRecording::count(),
            'completed' => Evaluation::where('status', 'completed')->count(),
            'pending' => Evaluation::whereIn('status', ['queued', 'transcribing', 'evaluating'])->count(),
            'failed' => Evaluation::where('status', 'failed')->count(),
            'reviewed_score' => Evaluation::where('review_status', 'reviewed')->avg('final_score'),
            'review_pending' => Evaluation::where('status', 'completed')->where('review_status', 'pending')->count(),
        ];

        $recent = Evaluation::with(['call', 'templateVersion'])->latest()->limit(8)->get();

        return view('livewire.dashboard', compact('totals', 'recent'))->layout('components.layouts.app', ['title' => 'Overview']);
    }
}
