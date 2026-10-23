<?php

namespace App\AI\Data;

final readonly class EvaluationResult
{
    /** @param array<int, array<string, mixed>> $results Validated criterion decisions and persisted segment references. */
    public function __construct(
        public array $results,
        public array $metadata,
    ) {
    }
}
