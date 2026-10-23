<?php

namespace App\AI;

use App\AI\Exceptions\ProcessingException;

final class TranscriptWindowOrder
{
    public function sort(array $records, array $segments): array
    {
        if (!array_is_list($records))
        {
            throw new ProcessingException('Saved window findings must be an ordered list.');
        }
        $sources = $this->sources($segments);
        $windows = [];

        foreach ($records as $record)
        {
            if (!is_array($record) || !is_array($record['metadata']['primary_spans'] ?? null) || !array_is_list($record['metadata']['primary_spans']) || $record['metadata']['primary_spans'] === [])
            {
                throw new ProcessingException('A saved window is missing its original primary byte ranges.');
            }
            $first = null;
            $last = null;

            foreach ($record['metadata']['primary_spans'] as $span)
            {
                $range = $this->range($span, $sources, 'segment_id');
                $this->assertAfter($last, $range['first']);
                $first ??= $range['first'];
                $last = $range['last'];
            }
            $windows[] = compact('record', 'first', 'last');
        }
        usort($windows, fn (array $left, array $right) => $this->compare($left['first'], $right['first']) ?: $this->compare($left['last'], $right['last']));
        $previous = null;

        foreach ($windows as $window)
        {
            $this->assertAfter($previous, $window['first']);
            $previous = $window['last'];
        }

        return array_column($windows, 'record');
    }

    public function groups(array $remaining, array $segments): array
    {
        if (!array_is_list($remaining))
        {
            throw new ProcessingException('Remaining transcript ranges must be an ordered list.');
        }
        $sources = $this->sources($segments);
        $ranges = [];

        foreach ($remaining as $span)
        {
            $range = $this->range($span, $sources, 'id');
            $ranges[] = ['span' => $span, ...$range];
        }
        usort($ranges, fn (array $left, array $right) => $this->compare($left['first'], $right['first']) ?: $this->compare($left['last'], $right['last']));
        $groups = [];
        $group = [];
        $previous = null;

        foreach ($ranges as $range)
        {
            $this->assertAfter($previous, $range['first']);

            if ($previous !== null && !$this->adjacent($previous, $range['first'], $sources))
            {
                $groups[] = $group;
                $group = [];
            }
            $group[] = $range['span'];
            $previous = $range['last'];
        }

        if ($group !== [])
        {
            $groups[] = $group;
        }

        return $groups;
    }

    private function sources(array $segments): array
    {
        if ($segments === [] || !array_is_list($segments))
        {
            throw new ProcessingException('Chronological windows require the ordered original transcript.');
        }
        $sources = [];

        foreach ($segments as $position => $segment)
        {
            if (!is_array($segment) || !is_int($segment['id'] ?? null) || $segment['id'] < 1 || isset($sources[$segment['id']]) || !is_string($segment['text'] ?? null) || $segment['text'] === '' || !mb_check_encoding($segment['text'], 'UTF-8'))
            {
                throw new ProcessingException('The original transcript has invalid identifiers or source text.');
            }
            $sources[$segment['id']] = ['position' => $position, 'text' => $segment['text'], 'length' => strlen($segment['text'])];
        }

        return $sources;
    }

    private function range(mixed $span, array $sources, string $identifier): array
    {
        if (!is_array($span) || !is_int($span[$identifier] ?? null) || !isset($sources[$span[$identifier]]) || !is_int($span['text_start'] ?? null) || !is_int($span['text_end'] ?? null))
        {
            throw new ProcessingException('A transcript window has invalid original byte references.');
        }
        $source = $sources[$span[$identifier]];
        $start = $span['text_start'];
        $end = $span['text_end'];

        if ($start < 0 || $end <= $start || $end > $source['length'])
        {
            throw new ProcessingException('A transcript window extends outside its original segment.');
        }
        $text = substr($source['text'], $start, $end - $start);

        if (!mb_check_encoding($text, 'UTF-8') || (array_key_exists('text', $span) && (!is_string($span['text']) || $span['text'] !== $text)))
        {
            throw new ProcessingException('A transcript window no longer matches its unmodified original text.');
        }

        return ['first' => [$source['position'], $start], 'last' => [$source['position'], $end]];
    }

    private function assertAfter(?array $previousEnd, array $nextStart): void
    {
        if ($previousEnd !== null && $this->compare($previousEnd, $nextStart) > 0)
        {
            throw new ProcessingException('Primary window ranges overlap or interleave. No chronological whole-call judgment was saved.');
        }
    }

    private function adjacent(array $previousEnd, array $nextStart, array $sources): bool
    {
        if ($previousEnd === $nextStart)
        {
            return true;
        }

        if ($nextStart[0] !== $previousEnd[0] + 1 || $nextStart[1] !== 0)
        {
            return false;
        }

        foreach ($sources as $source)
        {
            if ($source['position'] === $previousEnd[0])
            {
                return $previousEnd[1] === $source['length'];
            }
        }

        return false;
    }

    private function compare(array $left, array $right): int
    {
        return ($left[0] <=> $right[0]) ?: ($left[1] <=> $right[1]);
    }
}
