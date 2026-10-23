<?php

namespace App\AI;

use App\AI\Data\WindowResult;
use App\AI\Exceptions\ContextLimitException;
use App\AI\Exceptions\ProcessingException;

final class ReconciliationInput
{
    public function __construct(
        private WindowInput $input,
        private WindowResponseValidator $validator,
        private WindowPrompt $prompts,
        private OllamaChatClient $client,
        private OllamaRequestBudget $budget,
        private ReconciliationPrompt $compact,
    ) {
    }

    public function build(array $criterion, array $windowFindings, array $segments, array $context, int $contextTokens): array
    {
        $prepared = $this->prepare($criterion, $windowFindings, $segments, $context);
        $source = $prepared['source'];
        $findings = $prepared['findings'];
        $lengths = $prepared['lengths'];
        $messages = $this->prompts->reconciliation($criterion, $findings, $source, $context, true, $contextTokens);
        $schema = $this->prompts->evaluationSchema($contextTokens);

        if ($this->budget->fits($this->client->request($messages, $schema, $contextTokens), $contextTokens))
        {
            return $this->package($messages, $schema, $source, $findings, true, $lengths);
        }

        $wanted = [$source[0]['id'] => true, $source[array_key_last($source)]['id'] => true];

        foreach ($findings as $finding)
        {
            foreach ($finding['evidence'] as $evidence)
            {
                $wanted[$evidence['segment_id']] = true;
            }
        }

        $withNeighbors = $wanted;

        foreach ($source as $index => $segment)
        {
            if (!isset($wanted[$segment['id']]))
            {
                continue;
            }

            if (isset($source[$index - 1]))
            {
                $withNeighbors[$source[$index - 1]['id']] = true;
            }

            if (isset($source[$index + 1]))
            {
                $withNeighbors[$source[$index + 1]['id']] = true;
            }
        }

        $excerpts = array_values(array_filter($source, fn (array $segment) => isset($withNeighbors[$segment['id']])));
        $messages = $this->prompts->reconciliation($criterion, $findings, $excerpts, $context, false, $contextTokens);

        if ($this->budget->fits($this->client->request($messages, $schema, $contextTokens), $contextTokens))
        {
            return $this->package($messages, $schema, $excerpts, $findings, false, $lengths);
        }

        foreach ([[$source, true], [$excerpts, false]] as [$selected, $fullTranscript])
        {
            $messages = $this->compact->compact($criterion, $findings, $selected, $context, $fullTranscript, $contextTokens);

            if ($this->budget->fits($this->client->request($messages, $schema, $contextTokens), $contextTokens))
            {
                return $this->package($messages, $schema, $selected, $findings, $fullTranscript, $lengths) + ['prompt_version' => ReconciliationPrompt::VERSION];
            }
        }

        throw new ContextLimitException('Criterion '.$criterion['id'].' requires bounded reconciliation batches. Saved observations and the full transcript are preserved.');
    }

    public function prepare(array $criterion, array $windowFindings, array $segments, array $context): array
    {
        $this->input->criteria([$criterion]);
        $originals = $this->input->spans($segments);
        $source = [];
        $lengths = [];

        foreach ($originals as $segment)
        {
            if ($segment['text_start'] !== 0 || $segment['text_end'] !== strlen($segment['text']) || isset($lengths[$segment['id']]))
            {
                throw new ProcessingException('Whole-call reconciliation requires each complete original segment exactly once.');
            }

            $lengths[$segment['id']] = strlen($segment['text']);
            $source[] = array_intersect_key($segment, array_flip(['id', 'start', 'end', 'text', 'speaker']));
        }

        $segmentIds = array_keys($lengths);
        $this->validateExpectedSource($segmentIds, $lengths, $context);
        [$findings, $derivedCoverage, $records] = $this->findings($criterion['id'], $windowFindings, $segmentIds, $context);
        $coverage = array_key_exists('window_coverage', $context) ? ($context['window_coverage'][$criterion['id']] ?? null) : $derivedCoverage;
        $this->validateCoverage($coverage, $lengths);

        return compact('source', 'findings', 'lengths', 'coverage', 'records');
    }

    public function validateSourceCoverage(array $segments, array $coverage, array $context): void
    {
        $originals = $this->input->spans($segments);
        $lengths = [];

        foreach ($originals as $segment)
        {
            if ($segment['text_start'] !== 0 || isset($lengths[$segment['id']]))
            {
                throw new ProcessingException('Reconciliation requires unique complete original segments.');
            }
            $lengths[$segment['id']] = strlen($segment['text']);
        }
        $this->validateExpectedSource(array_keys($lengths), $lengths, $context);
        $this->validateCoverage($coverage, $lengths);
    }

    private function findings(int $criterionId, array $windowFindings, array $segmentIds, array $context): array
    {
        $findings = [];
        $coverage = [];
        $records = [];

        foreach ($windowFindings as $record)
        {
            if ($record instanceof WindowResult)
            {
                $record = ['observations' => $record->observations, 'metadata' => $record->metadata];
            }

            if (!is_array($record))
            {
                throw new ProcessingException('A saved window checkpoint has an invalid type.');
            }

            $observations = array_key_exists('observations', $record) ? $record['observations'] : [$record];

            if (!is_array($observations) || !array_is_list($observations))
            {
                throw new ProcessingException('A saved window checkpoint has invalid observations.');
            }

            $target = array_values(array_filter($observations, fn ($observation) => is_array($observation) && ($observation['criterion_id'] ?? null) === $criterionId));

            if ($target === [])
            {
                continue;
            }

            $validated = $this->validator->validate(['observations' => $target], [$criterionId], $segmentIds);
            $metadata = $record['metadata'] ?? [];

            if (!is_array($metadata))
            {
                throw new ProcessingException('A saved window checkpoint has invalid metadata.');
            }

            if (isset($context['model_digest'], $metadata['model_digest']) && !hash_equals($context['model_digest'], $metadata['model_digest']))
            {
                throw new ProcessingException('Saved observations do not match this evaluation run model digest.');
            }

            if (isset($metadata['primary_spans']))
            {
                if (!is_array($metadata['primary_spans']))
                {
                    throw new ProcessingException('A saved checkpoint has invalid source coverage.');
                }

                array_push($coverage, ...$metadata['primary_spans']);
            }

            $findings[] = $validated[0];
            $records[] = ['observation' => $validated[0], 'metadata' => $metadata];
        }

        if ($findings === [])
        {
            throw new ProcessingException('Whole-call reconciliation requires saved observations for this criterion.');
        }

        return [$findings, $coverage, $records];
    }

    private function validateExpectedSource(array $ids, array $lengths, array $context): void
    {
        if (isset($context['expected_segment_ids']))
        {
            $expected = $context['expected_segment_ids'];

            if (!is_array($expected) || count($expected) !== count($ids) || array_diff($expected, $ids) || array_diff($ids, $expected))
            {
                throw new ProcessingException('The original source segment set changed during evaluation.');
            }
        }

        if (isset($context['expected_span_lengths']) && $context['expected_span_lengths'] != $lengths)
        {
            throw new ProcessingException('The original transcript byte lengths changed during evaluation.');
        }
    }

    private function validateCoverage(mixed $coverage, array $lengths): void
    {
        if (!is_array($coverage) || !array_is_list($coverage) || $coverage === [])
        {
            throw new ProcessingException('Whole-call judgments require complete server-verified window coverage.');
        }

        $ranges = [];

        foreach ($coverage as $span)
        {
            if (!is_array($span) || !is_int($span['segment_id'] ?? null) || !is_int($span['text_start'] ?? null) || !is_int($span['text_end'] ?? null))
            {
                throw new ProcessingException('Window coverage contains invalid original byte offsets.');
            }

            $id = $span['segment_id'];

            if (!isset($lengths[$id]) || $span['text_start'] < 0 || $span['text_end'] <= $span['text_start'] || $span['text_end'] > $lengths[$id])
            {
                throw new ProcessingException('Window coverage does not match the original recording.');
            }

            $ranges[$id][] = [$span['text_start'], $span['text_end']];
        }

        foreach ($lengths as $id => $length)
        {
            $segmentRanges = $ranges[$id] ?? [];
            usort($segmentRanges, fn (array $left, array $right) => $left[0] <=> $right[0]);
            $cursor = 0;

            foreach ($segmentRanges as [$start, $end])
            {
                if ($start > $cursor)
                {
                    throw new ProcessingException('A transcript range has not been analyzed. No whole-call judgment was saved.');
                }

                $cursor = max($cursor, $end);
            }

            if ($cursor !== $length)
            {
                throw new ProcessingException('A transcript range has not been analyzed. No whole-call judgment was saved.');
            }
        }
    }

    private function package(array $messages, array $schema, array $segments, array $findings, bool $fullTranscript, array $lengths): array
    {
        return [
            'messages' => $messages, 'schema' => $schema, 'segment_ids' => array_column($segments, 'id'),
            'metadata' => ['coverage_checked' => true, 'source_segment_count' => count($lengths), 'finding_count' => count($findings), 'full_transcript_in_reconciliation' => $fullTranscript],
        ];
    }
}
