<?php

namespace App\AI\Contracts;

use App\AI\Data\EvaluationResult;

interface EvaluationProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $criteria  Published criteria, including id and pass_standard.
     * @param  array<int, array<string, mixed>>  $segments  Persisted same-call segments, including id, start, end, text, and nullable speaker.
     * @param  array<string, mixed>  $context  Call completeness and reliable speaker context, when known.
     */
    public function evaluate(array $criteria, array $segments, array $context = []): EvaluationResult;
}
