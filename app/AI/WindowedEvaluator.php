<?php

namespace App\AI;

use App\AI\Contracts\EvaluationProvider;
use App\AI\Data\EvaluationResult;
use App\AI\Data\WindowResult;
use App\AI\Exceptions\ContextLimitException;
use App\AI\Exceptions\ProcessingException;

final class WindowedEvaluator implements EvaluationProvider
{
    public function __construct(
        private OllamaChatClient $client,
        private TranscriptWindowPlanner $planner,
        private WindowInput $input,
        private WindowPrompt $prompts,
        private WindowResponseValidator $windowValidator,
        private EvaluationResponseValidator $evaluationValidator,
        private ReconciliationInput $reconciliation,
        private ReconciliationReducer $reducer,
    ) {
    }

    public function modelDigest(): string
    {
        return $this->client->modelDigest();
    }

    public function plan(array $criteria, array $segments, array $context, int $contextTokens): array
    {
        return $this->planner->plan($criteria, $segments, $context, $contextTokens);
    }

    public function inspect(array $criteria, array $spans, array $context, int $contextTokens): WindowResult
    {
        $criteria = $this->input->criteria($criteria);
        $spans = $this->input->spans($spans);
        $response = $this->client->complete(
            $this->prompts->window($criteria, $spans, $context, $contextTokens),
            $this->prompts->windowSchema(count($criteria), $contextTokens),
            $contextTokens,
            WindowPrompt::WINDOW_VERSION,
            $context['model_digest'] ?? null,
        );
        $observations = $this->windowValidator->validate($response->payload, array_column($criteria, 'id'), array_values(array_unique(array_column($spans, 'id'))));

        return new WindowResult($observations, $response->metadata + [
            'scope' => 'partial_window',
            'criterion_ids' => array_column($criteria, 'id'),
            'primary_spans' => $this->input->primarySpans($spans),
            'primary_segment_ids' => array_values(array_unique(array_column(array_filter($spans, fn (array $span) => !$span['overlap']), 'id'))),
            'source_spans_sha256' => hash('sha256', json_encode($spans, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
    }

    public function reconcile(array $criterion, array $windowFindings, array $segments, array $context, int $contextTokens): EvaluationResult
    {
        $input = $this->reconciliation->build($criterion, $windowFindings, $segments, $context, $contextTokens);
        $response = $this->client->complete($input['messages'], $input['schema'], $contextTokens, $input['prompt_version'] ?? WindowPrompt::RECONCILE_VERSION, $context['model_digest'] ?? null);

        return $this->wholeCallResult($criterion, $response->payload, $response->metadata, $input, $context);
    }

    public function reconciliationNodes(array $criterion, array $windowFindings, array $segments, array $context): array
    {
        return $this->reducer->leaves($criterion, $windowFindings, $segments, $context);
    }

    public function planReconciliation(array $criterion, array $nodes, array $segments, array $context, int $contextTokens): array
    {
        return $this->reducer->plan($criterion, $nodes, $segments, $context, $contextTokens);
    }

    public function reduce(array $criterion, array $nodes, array $segments, array $context, int $contextTokens): WindowResult
    {
        return $this->reducer->reduce($criterion, $nodes, $segments, $context, $contextTokens);
    }

    public function nodeFromReduction(WindowResult|array $output): array
    {
        return $this->reducer->nodeFromReduction($output);
    }

    public function reconcileNodes(array $criterion, array $nodes, array $segments, array $context, int $contextTokens): EvaluationResult
    {
        $input = $this->reducer->finalInput($criterion, $nodes, $segments, $context, $contextTokens);
        $response = $this->client->complete($input['messages'], $input['schema'], $contextTokens, $input['prompt_version'] ?? WindowPrompt::RECONCILE_VERSION, $context['model_digest'] ?? null);

        return $this->wholeCallResult($criterion, $response->payload, $response->metadata, $input, $context);
    }

    private function wholeCallResult(array $criterion, array $payload, array $metadata, array $input, array $context): EvaluationResult
    {
        $results = $this->evaluationValidator->validate($payload, [$criterion['id']], $input['segment_ids']);
        $result = $results[0];

        if ($result['evidence_basis'] === 'missing' && in_array($result['decision'], ['PASS', 'FAIL'], true) && ($context['is_complete'] ?? null) !== true)
        {
            throw new ProcessingException('A recording that is incomplete or has unknown completeness cannot prove a whole-call omission. No judgment was saved.');
        }

        $flags = $input['metadata']['flags'] ?? null;

        if ($flags !== null && $result['evidence_basis'] === 'missing' && in_array($result['decision'], ['PASS', 'FAIL'], true) && ($flags['uncertain'] || $flags['needs_context'] || in_array('unknown', $flags['applicability'], true)))
        {
            throw new ProcessingException('Unresolved original uncertainty or attribution cannot establish a whole-call omission. No judgment was saved.');
        }

        $seen = [];

        foreach ($result['evidence'] as $evidence)
        {
            $key = $evidence['segment_id'].':'.$evidence['role'];

            if (isset($seen[$key]))
            {
                throw new ProcessingException('The reconciled judgment contains duplicate evidence references.');
            }

            $seen[$key] = true;
        }

        return new EvaluationResult($results, $metadata + $input['metadata'] + ['scope' => 'whole_call_reconciliation']);
    }

    public function fitsReconciliation(array $criterion, array $windowFindings, array $segments, array $context, int $contextTokens): bool
    {
        try
        {
            $this->reconciliation->build($criterion, $windowFindings, $segments, $context, $contextTokens);

            return true;
        }
        catch (ContextLimitException)
        {
            return false;
        }
    }
}
