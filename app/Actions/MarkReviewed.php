<?php

namespace App\Actions;

use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MarkReviewed
{
    public function handle(Evaluation $evaluation, User $user): void
    {
        Gate::forUser($user)->authorize('review', $evaluation);

        DB::transaction(function () use ($evaluation, $user)
        {
            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($evaluation->id);

            if ($evaluation->status !== 'completed' || $evaluation->results()->where('final_decision', 'INSUFFICIENT_EVIDENCE')->exists())
            {
                throw ValidationException::withMessages(['review' => 'Resolve insufficient evidence before completing the review.']);
            }

            $evaluation->update(['review_status' => 'reviewed', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
        });
    }
}
