<?php

namespace App\AI;

use App\AI\Data\TranscriptionResult;
use App\AI\Exceptions\ProcessingException;

final class TranscriptionNormalizer
{
    public function normalize(array $payload, float $duration, string $language, array $metadata = []): TranscriptionResult
    {
        $rawSegments = $payload['transcription'] ?? null;

        if (!is_array($rawSegments) || !array_is_list($rawSegments) || $rawSegments === [])
        {
            throw new ProcessingException('Whisper did not produce any timestamped speech. The recording may be silent or inaudible.');
        }

        $segments = [];
        $previousStart = -1.0;

        foreach ($rawSegments as $raw)
        {
            if (!is_array($raw))
            {
                throw new ProcessingException('Whisper returned an invalid segment.');
            }

            $from = $raw['offsets']['from'] ?? null;
            $to = $raw['offsets']['to'] ?? null;
            $text = $raw['text'] ?? null;

            if (!is_int($from) || !is_int($to) || !is_string($text))
            {
                throw new ProcessingException('Whisper returned a segment without valid millisecond offsets or text.');
            }

            $start = (float) ($from / 1000);
            $end = (float) ($to / 1000);

            if ($start < 0 || $start >= $duration || $end <= $start || $start < $previousStart || $end > $duration + 0.05)
            {
                throw new ProcessingException('Whisper returned invalid or out-of-range timestamps. The transcript was not saved.');
            }

            // Whisper rounds the final timestamp to a time grid. Limit only that sub-frame rounding.
            $end = min($end, $duration);
            $text = trim($text);
            $previousStart = $start;

            if ($text === '')
            {
                continue;
            }

            $segments[] = ['sequence' => count($segments) + 1, 'start' => $start, 'end' => $end, 'text' => $text, 'speaker' => null];
        }

        if ($segments === [])
        {
            throw new ProcessingException('No intelligible speech was found in this recording.');
        }

        return new TranscriptionResult(implode(' ', array_column($segments, 'text')), $segments, $language, $duration, $metadata);
    }
}
