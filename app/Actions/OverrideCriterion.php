<?php

namespace App\Actions;

use App\Enums\Decision;
use App\Models\CriterionResult;
use App\Models\Evaluation;
use App\Models\ScoreOverride;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OverrideCriterion
{
    public function __construct(private RecalculateEvaluation $recalculate)
    {
    }

    public function handle(CriterionResult $result, User $user, string $decision, string $reason, int $expectedRevision): void
    {
        Gate::forUser($user)->authorize('review', $result->evaluation);

        Validator::make(['decision' => $decision, 'reason' => trim($reason)], ['decision' => ['required', Rule::enum(Decision::class)], 'reason' => 'required|string|min:5|max:2000'])->validate();

        DB::transaction(function () use ($result, $user, $decision, $reason, $expectedRevision)
        {
            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($result->evaluation_id);
            $result = CriterionResult::query()->lockForUpdate()->findOrFail($result->id);

            if ($evaluation->status !== 'completed' || $result->revision !== $expectedRevision)
            {
                throw ValidationException::withMessages(['reason' => 'This result changed or is unavailable. Refresh and review the latest decision.']);
            }

            ScoreOverride::create(['evaluation_id' => $evaluation->id, 'criterion_result_id' => $result->id, 'previous_decision' => $result->final_decision, 'new_decision' => $decision, 'reason' => trim($reason), 'user_id' => $user->id]);

            $result->update(['final_decision' => $decision, 'review_explanation' => trim($reason), 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'revision' => $result->revision + 1]);
            $evaluation->update(['review_status' => 'pending', 'reviewed_at' => null, 'reviewed_by' => null]);
            $this->recalculate->handle($evaluation);
        });
    }
}
