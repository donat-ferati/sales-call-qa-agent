<?php

namespace App\AI;

use App\AI\Exceptions\ProcessingException;

final class WindowInput
{
    public function criteria(array $criteria): array
    {
        if ($criteria === [] || !array_is_list($criteria))
        {
            throw new ProcessingException('Window analysis requires an ordered list of published criteria.');
        }

        $seen = [];

        foreach ($criteria as $criterion)
        {
            if (!is_array($criterion) || !is_int($criterion['id'] ?? null) || $criterion['id'] < 1 || isset($seen[$criterion['id']]))
            {
                throw new ProcessingException('Published criterion identifiers are invalid or duplicated.');
            }

            foreach (['question', 'pass_standard'] as $field)
            {
                if (!is_string($criterion[$field] ?? null) || trim($criterion[$field]) === '')
                {
                    throw new ProcessingException('A published criterion is missing its question or explicit pass standard.');
                }
            }

            foreach (['title', 'instructions', 'applicability'] as $field)
            {
                if (isset($criterion[$field]) && !is_string($criterion[$field]))
                {
                    throw new ProcessingException('Published criterion text has an invalid type.');
                }
            }

            $seen[$criterion['id']] = true;
        }

        return $criteria;
    }

    public function spans(array $segments): array
    {
        if ($segments === [] || !array_is_list($segments))
        {
            throw new ProcessingException('Window analysis requires ordered persisted transcript segments.');
        }

        return array_map(function (array $segment)
        {
            if (!is_int($segment['id'] ?? null) || $segment['id'] < 1 || !is_string($segment['text'] ?? null) || $segment['text'] === '' || !mb_check_encoding($segment['text'], 'UTF-8'))
            {
                throw new ProcessingException('A transcript segment has an invalid identifier or UTF-8 text.');
            }

            foreach (['start', 'end'] as $field)
            {
                if ((!is_int($segment[$field] ?? null) && !is_float($segment[$field] ?? null)) || !is_finite((float) $segment[$field]))
                {
                    throw new ProcessingException('A transcript segment has invalid timing.');
                }
            }

            if ($segment['start'] < 0 || $segment['end'] <= $segment['start'] || (isset($segment['speaker']) && !is_string($segment['speaker'])))
            {
                throw new ProcessingException('A transcript segment has invalid boundaries or speaker attribution.');
            }

            $start = $segment['text_start'] ?? 0;
            $end = $segment['text_end'] ?? $start + strlen($segment['text']);

            if (!is_int($start) || !is_int($end) || $start < 0 || $end - $start !== strlen($segment['text']))
            {
                throw new ProcessingException('Transcript fragment byte offsets do not match the unmodified source text.');
            }

            if (isset($segment['overlap']) && !is_bool($segment['overlap']))
            {
                throw new ProcessingException('Transcript overlap markers must be native booleans.');
            }

            return [
                'id' => $segment['id'], 'start' => (float) $segment['start'], 'end' => (float) $segment['end'],
                'text' => $segment['text'], 'speaker' => $segment['speaker'] ?? null,
                'text_start' => $start, 'text_end' => $end, 'overlap' => $segment['overlap'] ?? false,
            ];
        }, $segments);
    }

    public function primarySpans(array $spans): array
    {
        return array_values(array_map(fn (array $span) => ['segment_id' => $span['id'], 'text_start' => $span['text_start'], 'text_end' => $span['text_end']], array_filter($spans, fn (array $span) => !$span['overlap'])));
    }
}
