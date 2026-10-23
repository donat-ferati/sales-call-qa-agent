<?php

namespace App\AI\Data;

final readonly class TranscriptionResult
{
    /** @param array<int, array{sequence: int, start: float, end: float, text: string, speaker: ?string}> $segments */
    public function __construct(
        public string $text,
        public array $segments,
        public string $language,
        public float $duration,
        public array $metadata,
    ) {
    }
}
