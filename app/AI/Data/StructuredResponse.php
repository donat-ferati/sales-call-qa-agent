<?php

namespace App\AI\Data;

final readonly class StructuredResponse
{
    public function __construct(
        public array $payload,
        public array $metadata,
    ) {
    }
}
