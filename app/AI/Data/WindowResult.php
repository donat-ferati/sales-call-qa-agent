<?php

namespace App\AI\Data;

final readonly class WindowResult
{
    /** @param list<array<string, mixed>> $observations Validated local findings; these are not whole-call judgments. */
    public function __construct(
        public array $observations,
        public array $metadata,
    ) {
    }
}
