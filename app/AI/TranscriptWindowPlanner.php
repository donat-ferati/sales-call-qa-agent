<?php

namespace App\AI;

use App\AI\Exceptions\ContextLimitException;

final class TranscriptWindowPlanner
{
    public function __construct(
        private WindowInput $input,
        private WindowPrompt $prompts,
        private OllamaChatClient $client,
        private OllamaRequestBudget $budget,
    ) {
    }

    public function plan(array $criteria, array $segments, array $context, int $contextTokens): array
    {
        $criteria = $this->input->criteria($criteria);
        $segments = $this->input->spans($segments);
        $profile = $this->budget->profile($contextTokens);
        $probe = $segments[0];
        $probe['text'] = mb_substr($probe['text'], 0, 1, 'UTF-8');
        $probe['text_end'] = $probe['text_start'] + strlen($probe['text']);
        $probe['overlap'] = false;
        $groups = [];
        $group = [];

        foreach ($criteria as $criterion)
        {
            $candidate = [...$group, $criterion];

            if ($group !== [] && (count($candidate) > $profile['max_criteria'] || !$this->fits($candidate, [$probe], $context, $contextTokens)))
            {
                $groups[] = $group;
                $candidate = [$criterion];
            }

            if (!$this->fits($candidate, [$probe], $context, $contextTokens))
            {
                throw new ContextLimitException('Criterion '.$criterion['id'].' and the minimum complete analysis request cannot fit '.$contextTokens.' tokens. The rubric and transcript were not shortened.');
            }

            $group = $candidate;
        }

        $groups[] = $group;
        $work = [];

        foreach ($groups as $criteriaGroup)
        {
            $cursor = 0;
            $consumed = 0;
            $history = [];

            while ($cursor < count($segments))
            {
                $overlap = array_map(fn (array $span) => [...$span, 'overlap' => true], $history);
                $primary = [];

                while ($cursor < count($segments))
                {
                    $remaining = $segments[$cursor];
                    $remaining['text'] = substr($remaining['text'], $consumed);
                    $remaining['text_start'] += $consumed;
                    $remaining['overlap'] = false;
                    $candidate = [...$overlap, ...$primary, $remaining];

                    if ($this->fits($criteriaGroup, $candidate, $context, $contextTokens))
                    {
                        $primary[] = $remaining;
                        $cursor++;
                        $consumed = 0;

                        continue;
                    }

                    if ($primary !== [])
                    {
                        break;
                    }

                    if ($overlap !== [] && $this->fits($criteriaGroup, [$remaining], $context, $contextTokens))
                    {
                        array_shift($overlap);

                        continue;
                    }
                    $fragment = $this->fittingPrefix($criteriaGroup, [...$overlap, ...$primary], $remaining, $context, $contextTokens);

                    if ($fragment !== null)
                    {
                        $primary[] = $fragment;
                        $consumed += strlen($fragment['text']);

                        break;
                    }

                    if ($primary !== [])
                    {
                        break;
                    }

                    if ($overlap !== [])
                    {
                        array_shift($overlap);

                        continue;
                    }

                    throw new ContextLimitException('No UTF-8 transcript text can fit this complete '.$contextTokens.'-token request. Nothing was truncated.');
                }

                $spans = [...$overlap, ...$primary];
                $request = $this->request($criteriaGroup, $spans, $context, $contextTokens);
                $measurement = $this->budget->assertFits($request, $contextTokens);
                $work[] = [
                    'criterion_ids' => array_column($criteriaGroup, 'id'),
                    'spans' => $spans,
                    'primary_spans' => $this->input->primarySpans($primary),
                    'primary_segment_ids' => array_values(array_unique(array_column($primary, 'id'))),
                    'context_tokens' => $contextTokens,
                    'prompt_bytes' => $measurement['prompt_bytes'],
                    'input_sha256' => $measurement['request_sha256'],
                ];
                $history = array_map(function (array $span): array
                {
                    $tail = mb_strcut($span['text'], max(0, strlen($span['text']) - 256), null, 'UTF-8');

                    return [...$span, 'text' => $tail, 'text_start' => $span['text_end'] - strlen($tail)];
                }, array_slice($primary, -2));
            }
        }

        return $work;
    }

    private function fittingPrefix(array $criteria, array $preceding, array $span, array $context, int $contextTokens): ?array
    {
        $lower = 1;
        $upper = strlen($span['text']);
        $best = null;

        while ($lower <= $upper)
        {
            $length = intdiv($lower + $upper, 2);
            $text = mb_strcut($span['text'], 0, $length, 'UTF-8');
            $candidate = [...$span, 'text' => $text, 'text_end' => $span['text_start'] + strlen($text)];

            if ($this->fits($criteria, [...$preceding, $candidate], $context, $contextTokens))
            {
                if ($text !== '')
                {
                    $best = $candidate;
                }

                $lower = $length + 1;

                continue;
            }

            $upper = $length - 1;
        }

        return $best;
    }

    private function fits(array $criteria, array $spans, array $context, int $contextTokens): bool
    {
        return $this->budget->fits($this->request($criteria, $spans, $context, $contextTokens), $contextTokens);
    }

    private function request(array $criteria, array $spans, array $context, int $contextTokens): array
    {
        return $this->client->request($this->prompts->window($criteria, $spans, $context, $contextTokens), $this->prompts->windowSchema(count($criteria), $contextTokens), $contextTokens);
    }
}
