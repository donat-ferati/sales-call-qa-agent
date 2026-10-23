<?php

namespace App\Actions;

use App\AI\Data\EvaluationResult;
use App\Enums\Decision;
use App\Models\Evaluation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PersistEvaluation
{
    public function __construct(private RecalculateEvaluation $recalculate)
    {
    }

    public function handle(int $id, string $token, EvaluationResult $output): void
    {
        DB::transaction(function () use ($id, $token, $output)
        {
            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($id);

            if ($evaluation->attempt_token !== $token || $evaluation->status !== 'evaluating')
            {
                return;
            }

            $criteria = $evaluation->templateVersion->criteria->keyBy('id');
            $segments = $evaluation->call->segments->keyBy('id');
            $ids = array_column($output->results, 'criterion_id');

            if (count($ids) !== $criteria->count() || count(array_unique($ids)) !== count($ids) || array_diff($ids, $criteria->keys()->all()))
            {
                throw new RuntimeException('The scorecard does not match its template version.');
            }

            foreach ($output->results as $assessment)
            {
                $decision = Decision::from($assessment['decision']);

                if (trim($assessment['explanation']) === '')
                {
                    throw new RuntimeException('A criterion explanation is missing.');
                }

                $result = $evaluation->results()->create([
                    'template_criterion_id' => $assessment['criterion_id'],
                    'ai_decision' => $decision->value, 'final_decision' => $decision->value,
                    'ai_explanation' => $assessment['explanation'],
                    'uncertainty' => $assessment['uncertainty'], 'evidence_basis' => $assessment['evidence_basis'],
                ]);

                foreach ($assessment['evidence'] as $evidence)
                {
                    $segment = $segments->get($evidence['segment_id']);

                    if (!$segment || $segment->start < 0 || $segment->end <= $segment->start || $segment->end > $evaluation->call->duration + 0.1 || !in_array($evidence['role'], ['supporting', 'contradicting'], true))
                    {
                        throw new RuntimeException('Evidence does not belong to this recording or has invalid boundaries.');
                    }

                    $result->evidence()->create(['transcript_segment_id' => $segment->id, 'role' => $evidence['role']]);
                }
            }

            $evaluation->update(['status' => 'completed', 'completed_at' => now(), 'provider_metadata' => $output->metadata, 'attempt_token' => null, 'error_message' => null]);
            $evaluation->call->update(['status' => 'completed', 'error_message' => null]);
            $this->recalculate->handle($evaluation, true);
        });
    }
}
