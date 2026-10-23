<?php

namespace App\AI\Contracts;

use App\AI\Data\EvaluationResult;
use App\AI\Data\WindowResult;

/** Evaluates immutable call snapshots through individually persisted, bounded steps. */
interface EvaluationProvider
{
    public function modelDigest(): string;

    /** @return array<int, array<string, mixed>> Serializable window plans referencing original byte ranges. */
    public function plan(array $criteria, array $segments, array $context, int $contextTokens): array;

    public function inspect(array $criteria, array $spans, array $context, int $contextTokens): WindowResult;

    public function reconciliationNodes(array $criterion, array $windowFindings, array $segments, array $context): array;

    public function planReconciliation(array $criterion, array $nodes, array $segments, array $context, int $contextTokens): array;

    public function reduce(array $criterion, array $nodes, array $segments, array $context, int $contextTokens): WindowResult;

    public function nodeFromReduction(WindowResult|array $output): array;

    public function reconcileNodes(array $criterion, array $nodes, array $segments, array $context, int $contextTokens): EvaluationResult;
}
