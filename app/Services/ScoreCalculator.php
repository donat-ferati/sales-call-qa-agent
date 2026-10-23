<?php

namespace App\Services;

use App\Enums\Decision;
use InvalidArgumentException;

final class ScoreCalculator
{
    /** @param array<array{weight:int, decision:string}> $criteria */
    public function calculate(array $criteria): array
    {
        $applicable = $resolved = $earned = 0;

        foreach ($criteria as $criterion)
        {
            if (!is_int($criterion['weight']) || $criterion['weight'] < 1 || $criterion['weight'] > 100)
            {
                throw new InvalidArgumentException('Criterion weights must be integers between 1 and 100.');
            }
            $decision = Decision::from($criterion['decision']);

            if ($decision === Decision::NotApplicable)
            {
                continue;
            }
            $applicable += $criterion['weight'];

            if ($decision === Decision::InsufficientEvidence)
            {
                continue;
            }
            $resolved += $criterion['weight'];
            $earned += $decision === Decision::Pass ? $criterion['weight'] : 0;
        }

        return [
            'score' => $applicable > 0 && $resolved === $applicable ? round($earned / $applicable * 100, 2) : null,
            'provisional_score' => $resolved > 0 ? round($earned / $resolved * 100, 2) : null,
            'coverage' => $applicable > 0 ? round($resolved / $applicable * 100, 2) : 0,
        ];
    }
}
