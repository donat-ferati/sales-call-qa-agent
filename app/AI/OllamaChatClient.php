<?php

namespace App\AI;

use App\AI\Data\StructuredResponse;
use App\AI\Exceptions\MemoryExhaustedException;
use App\AI\Exceptions\ProcessingException;
use App\AI\Exceptions\TransientEvaluationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use JsonException;

final class OllamaChatClient
{
    public function __construct(
        private Factory $http,
        private Repository $config,
        private OllamaRequestBudget $budget,
    ) {
    }

    public function modelDigest(): string
    {
        try
        {
            $response = $this->localRequest(15)->get($this->baseUrl().'/api/tags');
        }
        catch (ConnectionException)
        {
            throw new TransientEvaluationException('Ollama is unavailable while checking the local model inventory. Retry after starting Ollama.');
        }

        if (!$response->successful())
        {
            throw new TransientEvaluationException('Ollama model inventory is unavailable (HTTP '.$response->status().').');
        }

        $models = $response->json('models');
        $model = $this->config->get('qa.ollama.model');

        if (is_array($models))
        {
            foreach ($models as $available)
            {
                if (($available['name'] ?? null) === $model && is_string($available['digest'] ?? null) && $available['digest'] !== '')
                {
                    return $available['digest'];
                }
            }
        }

        throw new ProcessingException('The configured Qwen3 model is not downloaded. Run ollama pull '.$model.' and retry.');
    }

    public function request(array $messages, array $schema, int $contextTokens): array
    {
        $profile = $this->budget->profile($contextTokens);

        return [
            'model' => $this->config->get('qa.ollama.model'),
            'messages' => $messages,
            'think' => false,
            'stream' => false,
            'keep_alive' => '10m',
            'format' => $schema,
            'options' => [
                'num_ctx' => $contextTokens,
                'num_predict' => $profile['output_tokens'],
                'temperature' => $this->config->get('qa.ollama.temperature'),
                'top_p' => $this->config->get('qa.ollama.top_p'),
                'top_k' => $this->config->get('qa.ollama.top_k'),
                'min_p' => $this->config->get('qa.ollama.min_p'),
            ],
        ];
    }

    public function complete(array $messages, array $schema, int $contextTokens, string $promptVersion, ?string $expectedDigest = null): StructuredResponse
    {
        $request = $this->request($messages, $schema, $contextTokens);
        $measurement = $this->budget->assertFits($request, $contextTokens);
        $started = microtime(true);
        $digest = $this->modelDigest();

        if ($expectedDigest !== null && !hash_equals($expectedDigest, $digest))
        {
            throw new ProcessingException('The local model digest changed during this evaluation. Start a new evaluation run.');
        }

        try
        {
            $response = $this->localRequest($this->config->get('qa.ollama.timeout'))->post($this->baseUrl().'/api/chat', $request);
        }
        catch (ConnectionException)
        {
            throw new TransientEvaluationException('Ollama is unavailable or evaluation timed out. No context downgrade was performed.');
        }

        $body = $response->json();
        $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : '';

        if ($error !== '' && $this->isMemoryError($error))
        {
            throw new MemoryExhaustedException('Ollama reported an explicit memory allocation failure at '.$contextTokens.' tokens. Saved transcript and checkpoints are preserved.');
        }

        if (!$response->successful() || $error !== '')
        {
            if (in_array($response->status(), [429, 502, 503, 504], true) || preg_match('/runner.*(?:terminated|unavailable|crashed)/i', $error))
            {
                throw new TransientEvaluationException('The local evaluator is temporarily unavailable (HTTP '.$response->status().'). No memory error was established.');
            }

            throw new ProcessingException('Ollama could not complete evaluation (HTTP '.$response->status().'). No score was saved.');
        }

        if (!is_array($body) || ($body['done'] ?? null) !== true || ($body['done_reason'] ?? null) !== 'stop')
        {
            throw new ProcessingException('The model response was incomplete or reached its output limit. No score was saved.');
        }

        $content = $body['message']['content'] ?? null;

        if (!is_string($content) || trim($content) === '')
        {
            throw new ProcessingException('The evaluator returned no final JSON answer. No score was saved.');
        }

        try
        {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (JsonException)
        {
            throw new ProcessingException('The evaluator returned malformed JSON. No score was saved.');
        }

        if (!is_array($payload))
        {
            throw new ProcessingException('The evaluator returned an unexpected JSON response. No score was saved.');
        }

        return new StructuredResponse($payload, $measurement + [
            'provider' => 'ollama',
            'model' => $request['model'],
            'model_digest' => $digest,
            'prompt_version' => $promptVersion,
            'prompt_sha256' => hash('sha256', $this->budget->encode($messages)),
            'settings' => $request['options'],
            'thinking' => false,
            'elapsed_seconds' => round(microtime(true) - $started, 3),
            'prompt_eval_count' => $body['prompt_eval_count'] ?? null,
            'eval_count' => $body['eval_count'] ?? null,
            'load_duration_ns' => $body['load_duration'] ?? null,
            'eval_duration_ns' => $body['eval_duration'] ?? null,
        ]);
    }

    private function localRequest(int $timeout): PendingRequest
    {
        // Neither redirects nor environment proxy settings may send local recording data elsewhere.
        return $this->http->withoutRedirecting()->withOptions(['proxy' => ''])
            ->connectTimeout($this->config->get('qa.ollama.connect_timeout'))->timeout($timeout);
    }

    private function baseUrl(): string
    {
        $configured = $this->config->get('qa.ollama.url');

        if (!is_string($configured))
        {
            throw new ProcessingException('The evaluator must use a local loopback Ollama HTTP address. Recording processing is local only.');
        }
        $baseUrl = rtrim($configured, '/');
        $parts = parse_url($baseUrl);

        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true) || !in_array($parts['host'] ?? null, ['127.0.0.1', 'localhost', '[::1]', '::1'], true))
        {
            throw new ProcessingException('The evaluator must use a local loopback Ollama HTTP address. Recording processing is local only.');
        }

        return $baseUrl;
    }

    private function isMemoryError(string $error): bool
    {
        return preg_match('/\bout of (?:device |gpu |system )?memory\b|cudaErrorMemoryAllocation|model requires more system memory|(?:failed|unable) to allocate[^\r\n]*(?:memory|(?:cuda|gpu|kv)[^\r\n]*buffer)|(?:cuda|gpu|kv)[^\r\n]*(?:failed|unable) to allocate/i', $error) === 1;
    }
}
