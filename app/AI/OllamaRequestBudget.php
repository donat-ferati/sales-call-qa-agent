<?php

namespace App\AI;

use App\AI\Exceptions\ContextLimitException;
use App\AI\Exceptions\ProcessingException;
use Illuminate\Contracts\Config\Repository;

final class OllamaRequestBudget
{
    public function __construct(private Repository $config)
    {
    }

    /** @return array{context_tokens:int,output_tokens:int,max_prompt_bytes:int,overhead_tokens:int,max_criteria:int} */
    public function profile(int $contextTokens): array
    {
        $profiles = [16384 => [4096, 11000, 4], 8192 => [2048, 5000, 2], 4096 => [1024, 2000, 1]];

        if (!isset($profiles[$contextTokens]))
        {
            throw new ProcessingException('Unsupported local context size. Use 16384, 8192, or 4096 tokens.');
        }

        [$outputTokens, $maxBytes, $maxCriteria] = $profiles[$contextTokens];

        if ($contextTokens === 16384)
        {
            $configuredBytes = $this->config->get('qa.ollama.max_prompt_bytes', $maxBytes);

            if (!is_int($configuredBytes) || $configuredBytes < 1 || $configuredBytes > $contextTokens - $outputTokens - 1024)
            {
                throw new ProcessingException('The configured prompt budget exceeds the local model context allowance.');
            }

            $maxBytes = min($maxBytes, $configuredBytes);
        }

        return ['context_tokens' => $contextTokens, 'output_tokens' => $outputTokens, 'max_prompt_bytes' => $maxBytes, 'overhead_tokens' => 1024, 'max_criteria' => $maxCriteria];
    }

    public function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function measure(array $request, int $contextTokens): array
    {
        $serialized = $this->encode($request);

        return $this->profile($contextTokens) + ['prompt_bytes' => strlen($serialized), 'request_sha256' => hash('sha256', $serialized)];
    }

    public function fits(array $request, int $contextTokens): bool
    {
        $measurement = $this->measure($request, $contextTokens);

        return $measurement['prompt_bytes'] <= $measurement['max_prompt_bytes'];
    }

    public function assertFits(array $request, int $contextTokens): array
    {
        $measurement = $this->measure($request, $contextTokens);

        // UTF-8 byte accounting includes the complete schema and request. It is intentionally conservative.
        if ($measurement['prompt_bytes'] > $measurement['max_prompt_bytes'])
        {
            throw new ContextLimitException('The complete request and response schema exceed the '.$contextTokens.'-token context budget ('.$measurement['prompt_bytes'].' of '.$measurement['max_prompt_bytes'].' input bytes). Nothing was truncated.');
        }

        return $measurement;
    }
}
