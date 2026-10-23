<?php

namespace App\AI;

use App\AI\Exceptions\ProcessingException;
use App\Models\EvaluationRun;
use App\Models\EvaluationStep;
use JsonException;
use TypeError;
use ValueError;

final class CheckpointCompatibility
{
    private const CRITERION_FIELDS = ['id', 'title', 'question', 'instructions', 'pass_standard', 'applicability', 'weight', 'required', 'scoring_type'];

    public function __construct(
        private WindowInput $input,
        private WindowPrompt $prompts,
        private OllamaChatClient $client,
        private OllamaRequestBudget $budget,
        private WindowResponseValidator $windows,
        private ReconciliationInput $reconciliationInput,
        private EvaluationResponseValidator $results,
    ) {
    }

    public function sameInputs(EvaluationRun $source, EvaluationRun $target): bool
    {
        return $source->evaluation_id === $target->evaluation_id
            && is_string($source->model_digest) && $source->model_digest !== ''
            && $source->model_digest === $target->model_digest
            && $this->same($source->criteria_snapshot, $target->criteria_snapshot)
            && $this->same($source->segments_snapshot, $target->segments_snapshot)
            && $this->same($this->providerContext($source->context), $this->providerContext($target->context));
    }

    public function window(EvaluationStep $step, EvaluationRun $source, EvaluationRun $target): bool
    {
        try
        {
            if (!$this->eligible($step, $source, $target, 'window'))
            {
                return false;
            }
            $payload = $step->payload;
            $output = $step->output;
            $metadata = $output['metadata'];

            if (!$this->metadata($metadata, $target, WindowPrompt::WINDOW_VERSION, 'partial_window') || !is_array($payload['spans'] ?? null) || !is_array($output['observations'] ?? null))
            {
                return false;
            }
            $spans = $this->input->spans($payload['spans']);
            $primary = $this->input->primarySpans($spans);

            if ($primary === [] || !$this->same($primary, $payload['primary_spans'] ?? null) || !$this->same($primary, $metadata['primary_spans'] ?? null)
                || !$this->same($step->criterion_ids, $metadata['criterion_ids'] ?? null) || !$this->sourceSpans($spans, $target->segments_snapshot)
                || !$this->digest($metadata['source_spans_sha256'] ?? null, hash('sha256', $this->budget->encode($spans))))
            {
                return false;
            }
            $segmentIds = array_values(array_unique(array_column($spans, 'id')));
            $this->windows->validate(['observations' => $output['observations']], $step->criterion_ids, $segmentIds);

            foreach ($this->criteriaCandidates($source, $target, $step->criterion_ids) as $criteria)
            {
                $this->input->criteria($criteria);

                foreach ($this->contextCandidates($source, $target) as $context)
                {
                    $messages = $this->prompts->window($criteria, $spans, $context, $step->context_tokens);
                    $schema = $this->prompts->windowSchema(count($criteria), $step->context_tokens);

                    if ($this->requestMatches($messages, $schema, $step->context_tokens, $metadata))
                    {
                        return true;
                    }
                }
            }
        }
        catch (ProcessingException|JsonException|TypeError|ValueError)
        {
            // An invalid legacy checkpoint is recomputed instead of being trusted.
        }

        return false;
    }

    public function reconciliation(EvaluationStep $step, EvaluationRun $source, EvaluationRun $target, array $findings, array $context): bool
    {
        try
        {
            if (!$this->eligible($step, $source, $target, 'reconcile') || count($step->criterion_ids) !== 1)
            {
                return false;
            }
            $output = $step->output;
            $metadata = $output['metadata'];

            if (!$this->metadata($metadata, $target, WindowPrompt::RECONCILE_VERSION, 'whole_call_reconciliation') || !is_array($output['results'] ?? null))
            {
                return false;
            }

            foreach ($this->criteriaCandidates($source, $target, $step->criterion_ids) as $criteria)
            {
                foreach ($this->contextCandidates($source, $target) as $candidate)
                {
                    $candidate = $candidate + $context;
                    $input = $this->reconciliationInput->build($criteria[0], $findings, $target->segments_snapshot, $candidate, $step->context_tokens);

                    if (($input['prompt_version'] ?? WindowPrompt::RECONCILE_VERSION) !== WindowPrompt::RECONCILE_VERSION
                        || !$this->requestMatches($input['messages'], $input['schema'], $step->context_tokens, $metadata))
                    {
                        continue;
                    }
                    $results = $this->results->validate(['results' => $output['results']], $step->criterion_ids, $input['segment_ids']);
                    $result = $results[0];

                    if ($result['evidence_basis'] === 'missing' && in_array($result['decision'], ['PASS', 'FAIL'], true) && ($candidate['is_complete'] ?? null) !== true)
                    {
                        return false;
                    }
                    $seen = [];

                    foreach ($result['evidence'] as $evidence)
                    {
                        $key = $evidence['segment_id'].':'.$evidence['role'];

                        if (isset($seen[$key]))
                        {
                            return false;
                        }
                        $seen[$key] = true;
                    }

                    return true;
                }
            }
        }
        catch (ProcessingException|JsonException|TypeError|ValueError)
        {
            // Only an exact, currently valid judgment can be copied to another run.
        }

        return false;
    }

    private function providerContext(array $context): array
    {
        // Historical runs predate provider identity and were produced by this built-in implementation.
        return $context + ['evaluation_provider' => WindowedEvaluator::class];
    }

    private function eligible(EvaluationStep $step, EvaluationRun $source, EvaluationRun $target, string $kind): bool
    {
        return $this->sameInputs($source, $target) && $step->evaluation_run_id === $source->id && $step->kind === $kind && $step->status === 'completed'
            && is_array($step->payload) && is_array($step->output) && is_array($step->output['metadata'] ?? null)
            && is_array($step->criterion_ids) && array_is_list($step->criterion_ids) && $step->criterion_ids !== [];
    }

    private function metadata(array $metadata, EvaluationRun $target, string $version, string $scope): bool
    {
        return ($metadata['model_digest'] ?? null) === $target->model_digest && ($metadata['prompt_version'] ?? null) === $version
            && ($metadata['scope'] ?? null) === $scope && ($metadata['thinking'] ?? null) === false
            && is_string($metadata['request_sha256'] ?? null) && is_string($metadata['prompt_sha256'] ?? null);
    }

    private function sourceSpans(array $spans, array $segments): bool
    {
        $originals = [];

        foreach ($this->input->spans($segments) as $segment)
        {
            $originals[$segment['id']] = $segment;
        }

        foreach ($spans as $span)
        {
            if (!isset($originals[$span['id']]))
            {
                return false;
            }
            $original = $originals[$span['id']];

            if ($span['text_end'] > strlen($original['text']) || $span['start'] !== $original['start'] || $span['end'] !== $original['end']
                || $span['speaker'] !== $original['speaker'] || substr($original['text'], $span['text_start'], $span['text_end'] - $span['text_start']) !== $span['text'])
            {
                return false;
            }
        }

        return true;
    }

    private function criteriaCandidates(EvaluationRun $source, EvaluationRun $target, array $ids): array
    {
        $candidates = [];

        foreach ([$source->criteria_snapshot, $target->criteria_snapshot] as $snapshot)
        {
            $criteria = array_values(array_filter($snapshot, fn (array $criterion) => in_array($criterion['id'], $ids, true)));

            if (array_column($criteria, 'id') !== $ids)
            {
                throw new ProcessingException('A checkpoint references a different ordered criterion set.');
            }
            $candidates[] = $criteria;
            $candidates[] = array_map(fn (array $criterion) => $this->ordered($criterion, self::CRITERION_FIELDS), $criteria);
        }

        return $candidates;
    }

    private function contextCandidates(EvaluationRun $source, EvaluationRun $target): array
    {
        return [$source->context, $target->context, $this->ordered($source->context, ['is_complete', 'duration', 'call_id']), $this->ordered($target->context, ['is_complete', 'duration', 'call_id'])];
    }

    private function ordered(array $values, array $keys): array
    {
        $ordered = [];

        foreach ($keys as $key)
        {
            if (array_key_exists($key, $values))
            {
                $ordered[$key] = $values[$key];
            }
        }

        return $ordered;
    }

    private function requestMatches(array $messages, array $schema, int $contextTokens, array $metadata): bool
    {
        $measurement = $this->budget->measure($this->client->request($messages, $schema, $contextTokens), $contextTokens);

        return $measurement['prompt_bytes'] <= $measurement['max_prompt_bytes']
            && $this->digest($metadata['request_sha256'], $measurement['request_sha256'])
            && $this->digest($metadata['prompt_sha256'], hash('sha256', $this->budget->encode($messages)));
    }

    private function digest(mixed $saved, string $expected): bool
    {
        return is_string($saved) && hash_equals($saved, $expected);
    }

    private function same(mixed $left, mixed $right): bool
    {
        return $this->canonical($left) === $this->canonical($right);
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value))
        {
            return $value;
        }

        if (!array_is_list($value))
        {
            ksort($value);
        }

        return array_map(fn (mixed $item) => $this->canonical($item), $value);
    }
}
