<?php

namespace App\AI;

use App\AI\Data\WindowResult;
use App\AI\Exceptions\ContextLimitException;
use App\AI\Exceptions\ProcessingException;

final class ReconciliationReducer
{
    private const MAX_LEVEL = 8;

    public function __construct(
        private ReconciliationInput $input,
        private ReconciliationPrompt $prompts,
        private WindowPrompt $schemas,
        private WindowResponseValidator $validator,
        private OllamaChatClient $client,
        private OllamaRequestBudget $budget,
        private TranscriptWindowOrder $order,
    ) {
    }

    public function leaves(array $criterion, array $findings, array $segments, array $context): array
    {
        $prepared = $this->input->prepare($criterion, $findings, $segments, $context);
        $nodes = [];

        foreach ($this->order->sort($prepared['records'], $segments) as $index => $record)
        {
            $observation = $record['observation'];
            $metadata = $record['metadata'];
            $coverage = $metadata['primary_spans'] ?? [];

            if (!is_array($coverage) || !array_is_list($coverage) || $coverage === [])
            {
                throw new ProcessingException('A reconciliation source is missing its immutable window coverage.');
            }
            $this->validateSpans($coverage, $segments);
            $analyzed = $metadata['analyzed_spans'] ?? $coverage;
            $this->validateSpans($analyzed, $segments);
            $id = $this->hash([$metadata['source_step_key'] ?? null, $observation, $coverage, $analyzed]);
            $node = [
                'id' => $id, 'child_ids' => [], 'source_ids' => [$id], 'order' => [$index, $index],
                'observation' => $observation, 'coverage' => $coverage, 'evidence_ledger' => $observation['evidence'],
                'evidence_spans' => $this->evidenceSpans($observation['evidence'], $analyzed),
                'flags' => [
                    'statuses' => [$observation['status']], 'applicability' => [$observation['applicability']],
                    'needs_context' => $observation['needs_context'], 'uncertain' => $observation['uncertainty'] !== null || $observation['status'] === 'uncertain',
                ],
                'level' => 0,
            ];
            $node['checksum'] = $this->hash($node);
            $nodes[] = $node;
        }

        return $nodes;
    }

    public function plan(array $criterion, array $nodes, array $segments, array $context, int $contextTokens): array
    {
        $this->validateNodes($criterion, $nodes, $segments, $context, true);

        if ($this->fits($this->build($criterion, $nodes, $segments, $context, $contextTokens, true), $contextTokens))
        {
            return ['kind' => 'reconcile', 'nodes' => $nodes];
        }
        $batches = [];
        $current = [];

        foreach ($nodes as $node)
        {
            if ($node['level'] >= self::MAX_LEVEL)
            {
                throw new ContextLimitException('Criterion '.$criterion['id'].' reached the bounded reconciliation depth. All source observations and checkpoints remain saved.');
            }
            $candidate = [...$current, $node];

            if ($this->fits($this->build($criterion, $candidate, $segments, $context, $contextTokens, false), $contextTokens))
            {
                $current = $candidate;

                continue;
            }

            if (count($current) > 1)
            {
                $batches[] = $current;
            }
            $current = [$node];

            if (!$this->fits($this->build($criterion, $current, $segments, $context, $contextTokens, false), $contextTokens))
            {
                throw new ContextLimitException('A single criterion '.$criterion['id'].' reconciliation input cannot fit the '.$contextTokens.'-token allowance. No source text or observations were discarded.');
            }
        }

        if (count($current) > 1)
        {
            $batches[] = $current;
        }

        if ($batches === [])
        {
            throw new ContextLimitException('Criterion '.$criterion['id'].' cannot be safely reduced further within '.$contextTokens.' tokens. Saved evidence, contradictions, uncertainties and transcript remain available.');
        }

        return ['kind' => 'reduce', 'batches' => $batches];
    }

    public function reduce(array $criterion, array $nodes, array $segments, array $context, int $contextTokens): WindowResult
    {
        $this->validateNodes($criterion, $nodes, $segments, $context, false);

        if (count($nodes) < 2 || max(array_column($nodes, 'level')) >= self::MAX_LEVEL)
        {
            throw new ProcessingException('A bounded reduction must merge at least two compatible nodes below the depth limit.');
        }
        $input = $this->build($criterion, $nodes, $segments, $context, $contextTokens, false);
        $response = $this->client->complete($input['messages'], $input['schema'], $contextTokens, ReconciliationPrompt::REDUCTION_VERSION, $context['model_digest'] ?? null);
        $observations = $this->validator->validate($response->payload, [$criterion['id']], $input['segment_ids']);
        $observation = $observations[0];
        $ledger = $this->uniqueRows(array_merge(...array_column($nodes, 'evidence_ledger')));
        $flags = $this->flags($nodes);
        $this->validateSummary($observation, $ledger, $flags);
        $flags = [
            'statuses' => array_values(array_unique([...$flags['statuses'], $observation['status']])),
            'applicability' => array_values(array_unique([...$flags['applicability'], $observation['applicability']])),
            'needs_context' => $flags['needs_context'] || $observation['needs_context'],
            'uncertain' => $flags['uncertain'] || $observation['uncertainty'] !== null || $observation['status'] === 'uncertain',
        ];
        $node = [
            'id' => $this->hash([array_column($nodes, 'id'), $observation, $context['model_digest'] ?? null, $contextTokens, ReconciliationPrompt::REDUCTION_VERSION]),
            'child_ids' => array_column($nodes, 'id'), 'source_ids' => array_merge(...array_column($nodes, 'source_ids')),
            'order' => [$nodes[0]['order'][0], $nodes[array_key_last($nodes)]['order'][1]],
            'observation' => $observation, 'coverage' => $this->uniqueRows(array_merge(...array_column($nodes, 'coverage'))),
            'evidence_ledger' => $ledger, 'evidence_spans' => $this->uniqueRows(array_merge(...array_column($nodes, 'evidence_spans'))),
            'flags' => $flags, 'level' => max(array_column($nodes, 'level')) + 1,
        ];
        $node['checksum'] = $this->hash($node);

        return new WindowResult($observations, $response->metadata + [
            'scope' => 'partial_reduction', 'reconciliation_node' => $node,
            'child_ids' => $node['child_ids'], 'source_ids' => $node['source_ids'],
            'primary_spans' => $node['coverage'], 'flags' => $flags,
        ]);
    }

    public function finalInput(array $criterion, array $nodes, array $segments, array $context, int $contextTokens): array
    {
        $this->validateNodes($criterion, $nodes, $segments, $context, true);
        $input = $this->build($criterion, $nodes, $segments, $context, $contextTokens, true);
        $this->budget->assertFits($this->client->request($input['messages'], $input['schema'], $contextTokens), $contextTokens);

        return $input;
    }

    public function nodeFromReduction(WindowResult|array $output): array
    {
        $metadata = $output instanceof WindowResult ? $output->metadata : ($output['metadata'] ?? null);
        $node = is_array($metadata) ? ($metadata['reconciliation_node'] ?? null) : null;

        if (!is_array($node))
        {
            throw new ProcessingException('The saved partial reconciliation has no validated lineage node.');
        }
        $this->validateChecksum($node);

        return $node;
    }

    private function build(array $criterion, array $nodes, array $segments, array $context, int $contextTokens, bool $final): array
    {
        if ($final && array_filter($nodes, fn (array $node) => $node['level'] !== 0) === [])
        {
            $findings = array_map(fn (array $node) => [
                'observations' => [$node['observation']],
                'metadata' => ['primary_spans' => $node['coverage'], 'model_digest' => $context['model_digest'] ?? null],
            ], $nodes);

            try
            {
                $input = $this->input->build($criterion, $findings, $segments, $context, $contextTokens);
                $input['metadata'] += [
                    'reconciliation_source_ids' => array_merge(...array_column($nodes, 'source_ids')),
                    'child_ids' => array_column($nodes, 'id'), 'lossless_reconciliation' => true,
                ];

                return $input;
            }
            catch (ContextLimitException)
            {
                // Only then use bounded partial summaries and explicitly marked original evidence excerpts.
            }
        }
        $excerpts = $this->excerpts($nodes, $segments, $contextTokens === 4096 ? 96 : 256);

        return [
            'prompt_version' => ReconciliationPrompt::VERSION,
            'messages' => $this->prompts->nodes($criterion, $nodes, $excerpts, $context, $final, $contextTokens),
            'schema' => $final ? $this->schemas->evaluationSchema($contextTokens) : $this->schemas->windowSchema(1, $contextTokens),
            'segment_ids' => array_values(array_unique(array_column($excerpts, 'id'))),
            'metadata' => [
                'coverage_checked' => $final, 'reconciliation_source_ids' => array_merge(...array_column($nodes, 'source_ids')),
                'child_ids' => array_column($nodes, 'id'), 'flags' => $this->flags($nodes),
                'evidence_ledger' => $this->uniqueRows(array_merge(...array_column($nodes, 'evidence_ledger'))),
                'full_transcript_in_reconciliation' => false, 'bounded_evidence_excerpts' => true,
                'lossless_reconciliation' => false, 'original_evidence_preserved_in_ledger' => true,
            ],
        ];
    }

    private function validateNodes(array $criterion, array $nodes, array $segments, array $context, bool $complete): void
    {
        if ($nodes === [] || !array_is_list($nodes))
        {
            throw new ProcessingException('Reconciliation requires an ordered nonempty node frontier.');
        }
        $sourceIds = [];
        $coverage = [];
        $previous = null;

        foreach ($nodes as $node)
        {
            $this->validateChecksum($node);

            if (($node['observation']['criterion_id'] ?? null) !== $criterion['id'] || !is_int($node['level']) || $node['level'] < 0 || !is_int($node['order'][0] ?? null) || !is_int($node['order'][1] ?? null) || $node['order'][1] < $node['order'][0] || ($previous !== null && $node['order'][0] !== $previous + 1))
            {
                throw new ProcessingException('Reconciliation lineage is not contiguous or belongs to another criterion.');
            }
            $previous = $node['order'][1];
            $this->validator->validate(['observations' => [$node['observation']]], [$criterion['id']], array_column($segments, 'id'));
            $this->validateSpans($node['coverage'], $segments);
            $this->validateSpans($node['evidence_spans'], $segments, true);
            array_push($sourceIds, ...$node['source_ids']);
            array_push($coverage, ...$node['coverage']);
        }

        if (count($sourceIds) !== count(array_unique($sourceIds)))
        {
            throw new ProcessingException('Reconciliation contains duplicated original window sources.');
        }

        if ($complete)
        {
            $expected = $context['reconciliation_source_ids'] ?? null;

            if (!is_array($expected) || !array_is_list($expected) || $sourceIds !== $expected)
            {
                throw new ProcessingException('Whole-call reconciliation requires every original window source exactly once in chronology.');
            }
            $this->input->validateSourceCoverage($segments, $coverage, $context);
        }
    }

    private function validateChecksum(array $node): void
    {
        $checksum = $node['checksum'] ?? null;
        unset($node['checksum']);

        if (!is_string($checksum) || !hash_equals($checksum, $this->hash($node)))
        {
            throw new ProcessingException('A saved reconciliation node changed after validation.');
        }

        foreach (['id', 'child_ids', 'source_ids', 'order', 'observation', 'coverage', 'evidence_ledger', 'evidence_spans', 'flags', 'level'] as $field)
        {
            if (!array_key_exists($field, $node))
            {
                throw new ProcessingException('A saved reconciliation node is incomplete.');
            }
        }

        if (!is_string($node['id']) || preg_match('/^[a-f0-9]{64}$/D', $node['id']) !== 1 || !is_int($node['level']) || $node['level'] < 0 || $node['level'] > self::MAX_LEVEL)
        {
            throw new ProcessingException('A saved reconciliation node has an invalid identifier or depth.');
        }

        foreach (['child_ids', 'source_ids', 'order', 'coverage', 'evidence_ledger', 'evidence_spans'] as $field)
        {
            if (!is_array($node[$field]) || !array_is_list($node[$field]))
            {
                throw new ProcessingException('A reconciliation lineage field has an invalid type.');
            }
        }

        foreach (array_merge($node['child_ids'], $node['source_ids']) as $id)
        {
            if (!is_string($id) || preg_match('/^[a-f0-9]{64}$/D', $id) !== 1)
            {
                throw new ProcessingException('A reconciliation source reference has an invalid type.');
            }
        }

        if (count($node['order']) !== 2 || !is_int($node['order'][0]) || !is_int($node['order'][1]) || $node['order'][0] < 0 || $node['order'][1] < $node['order'][0] || count($node['source_ids']) !== $node['order'][1] - $node['order'][0] + 1 || ($node['level'] === 0 && ($node['child_ids'] !== [] || $node['source_ids'] !== [$node['id']])) || ($node['level'] > 0 && count($node['child_ids']) < 2))
        {
            throw new ProcessingException('A saved reconciliation node has inconsistent chronological lineage.');
        }

        if (!is_array($node['flags']) || !is_array($node['flags']['statuses'] ?? null) || !array_is_list($node['flags']['statuses']) || $node['flags']['statuses'] === [] || array_diff($node['flags']['statuses'], WindowResponseValidator::STATUSES) || !is_array($node['flags']['applicability'] ?? null) || !array_is_list($node['flags']['applicability']) || $node['flags']['applicability'] === [] || array_diff($node['flags']['applicability'], ['applicable', 'not_applicable', 'unknown']) || !is_bool($node['flags']['needs_context'] ?? null) || !is_bool($node['flags']['uncertain'] ?? null))
        {
            throw new ProcessingException('A saved reconciliation node lost its original status or uncertainty constraints.');
        }
    }

    private function validateSummary(array $observation, array $ledger, array $flags): void
    {
        $allowed = array_map(fn (array $reference) => $reference['segment_id'].':'.$reference['role'], $ledger);
        $roles = array_unique(array_column($ledger, 'role'));

        foreach ($observation['evidence'] as $reference)
        {
            if (!in_array($reference['segment_id'].':'.$reference['role'], $allowed, true))
            {
                throw new ProcessingException('A partial reconciliation introduced unsupported evidence or changed its role.');
            }
        }

        if (array_diff($roles, array_column($observation['evidence'], 'role')) || ($roles !== [] && $observation['status'] === 'not_observed') || (count($roles) === 2 && !in_array($observation['status'], ['mixed', 'uncertain'], true)))
        {
            throw new ProcessingException('A partial reconciliation discarded supporting or contradictory findings.');
        }

        if (($flags['uncertain'] && (!is_string($observation['uncertainty']) || trim($observation['uncertainty']) === '')) || ($flags['needs_context'] && !$observation['needs_context']))
        {
            throw new ProcessingException('A partial reconciliation discarded an unresolved uncertainty or attribution constraint.');
        }

        if ((count($flags['applicability']) > 1 || in_array('unknown', $flags['applicability'], true)) && $observation['applicability'] !== 'unknown')
        {
            throw new ProcessingException('A partial reconciliation resolved uncertain applicability without a whole-call judgment.');
        }

        if (mb_strlen($observation['explanation']) > 400 || ($observation['uncertainty'] !== null && mb_strlen($observation['uncertainty']) > 400))
        {
            throw new ProcessingException('A partial reconciliation exceeded its bounded summary size. Original observations remain saved.');
        }
    }

    private function evidenceSpans(array $evidence, array $analyzed): array
    {
        $spans = [];

        foreach ($evidence as $reference)
        {
            $matched = array_filter($analyzed, fn (array $span) => $span['segment_id'] === $reference['segment_id']);

            if ($matched === [])
            {
                throw new ProcessingException('A window evidence reference has no analyzed original source span.');
            }

            foreach ($matched as $span)
            {
                $spans[] = [...$span, 'role' => $reference['role']];
            }
        }

        return $this->uniqueRows($spans);
    }

    private function excerpts(array $nodes, array $segments, int $maxBytes): array
    {
        $originals = array_column($segments, null, 'id');
        $selected = [];

        foreach ($nodes as $node)
        {
            $roles = [];

            foreach ($node['observation']['evidence'] as $reference)
            {
                if (isset($roles[$reference['role']]))
                {
                    continue;
                }
                $roles[$reference['role']] = true;
                $spans = array_values(array_filter($node['evidence_spans'], fn (array $span) => $span['segment_id'] === $reference['segment_id'] && $span['role'] === $reference['role']));

                if ($spans === [])
                {
                    throw new ProcessingException('A reconciliation reference lost its original analyzed byte range.');
                }
                $span = $spans[0];
                $segment = $originals[$span['segment_id']];
                $text = mb_strcut($segment['text'], $span['text_start'], min($span['text_end'] - $span['text_start'], $maxBytes), 'UTF-8');
                $selected[] = [...$segment, 'text' => $text, 'text_start' => $span['text_start'], 'text_end' => $span['text_start'] + strlen($text)];
            }
        }

        return $this->uniqueRows($selected);
    }

    private function validateSpans(array $spans, array $segments, bool $emptyAllowed = false): void
    {
        if (!array_is_list($spans) || (!$emptyAllowed && $spans === []))
        {
            throw new ProcessingException('Reconciliation spans must be an ordered original byte-range list.');
        }
        $originals = array_column($segments, null, 'id');

        foreach ($spans as $span)
        {
            if (!is_array($span) || !is_int($span['segment_id'] ?? null) || !isset($originals[$span['segment_id']]) || !is_int($span['text_start'] ?? null) || !is_int($span['text_end'] ?? null) || $span['text_start'] < 0 || $span['text_end'] <= $span['text_start'] || $span['text_end'] > strlen($originals[$span['segment_id']]['text']))
            {
                throw new ProcessingException('A reconciliation source span does not match the persisted original transcript.');
            }
            $text = substr($originals[$span['segment_id']]['text'], $span['text_start'], $span['text_end'] - $span['text_start']);

            if (!mb_check_encoding($text, 'UTF-8'))
            {
                throw new ProcessingException('A reconciliation source span splits an original UTF-8 character.');
            }
        }
    }

    private function flags(array $nodes): array
    {
        return [
            'statuses' => array_values(array_unique(array_merge(...array_map(fn (array $node) => $node['flags']['statuses'], $nodes)))),
            'applicability' => array_values(array_unique(array_merge(...array_map(fn (array $node) => $node['flags']['applicability'], $nodes)))),
            'needs_context' => in_array(true, array_map(fn (array $node) => $node['flags']['needs_context'], $nodes), true),
            'uncertain' => in_array(true, array_map(fn (array $node) => $node['flags']['uncertain'], $nodes), true),
        ];
    }

    private function uniqueRows(array $rows): array
    {
        $unique = [];

        foreach ($rows as $row)
        {
            $unique[$this->hash($row)] = $row;
        }

        return array_values($unique);
    }

    private function fits(array $input, int $contextTokens): bool
    {
        return $this->budget->fits($this->client->request($input['messages'], $input['schema'], $contextTokens), $contextTokens);
    }

    private function canonical(array $value): array
    {
        if (!array_is_list($value))
        {
            ksort($value);
        }

        foreach ($value as $key => $item)
        {
            if (is_array($item))
            {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }

    private function hash(array $value): string
    {
        return hash('sha256', $this->budget->encode($this->canonical($value)));
    }
}
