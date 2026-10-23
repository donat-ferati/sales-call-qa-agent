<?php

namespace App\Actions;

use App\Models\Evaluation;
use App\Services\ScoreCalculator;

final class RecalculateEvaluation
{
    public function __construct(private ScoreCalculator $calculator)
    {
    }

    public function handle(Evaluation $evaluation, bool $includeAi = false): void
    {
        $results = $evaluation->results()->with('criterion')->get();
        $final = $this->calculator->calculate($results->map(fn ($result) => ['weight' => $result->criterion->weight, 'decision' => $result->final_decision])->all());
        $attributes = ['final_score' => $final['score'], 'final_provisional_score' => $final['provisional_score'], 'final_coverage' => $final['coverage']];

        if ($includeAi)
        {
            $ai = $this->calculator->calculate($results->map(fn ($result) => ['weight' => $result->criterion->weight, 'decision' => $result->ai_decision])->all());
            $attributes += ['ai_score' => $ai['score'], 'ai_provisional_score' => $ai['provisional_score'], 'ai_coverage' => $ai['coverage']];
        }

        $evaluation->update($attributes);
    }
}
