<?php

namespace Tests\Unit;

use App\AI\Contracts\EvaluationProvider;
use App\AI\Exceptions\ProcessingException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WindowedEvaluationTest extends TestCase
{
    private array $criterion = ['id' => 11, 'question' => 'Did the agent explain the next step?', 'pass_standard' => 'The agent gives a clear next step.'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_large_reconciliation_batches_preserve_sources_and_contradictions_until_one_final_judgment(): void
    {
        [$segments, $findings] = $this->fixture(12, 900);
        $provider = app(EvaluationProvider::class);
        $context = $this->context($segments);
        $nodes = $provider->reconciliationNodes($this->criterion, array_reverse($findings), $segments, $context);
        $context['reconciliation_source_ids'] = array_column($nodes, 'id');
        $frontier = $nodes;
        $reductions = 0;
        $this->fakeInference();

        for ($level = 0; $level < 8; $level++)
        {
            $plan = $provider->planReconciliation($this->criterion, $frontier, $segments, $context, 16384);

            if ($plan['kind'] === 'reconcile')
            {
                break;
            }
            $previousCount = count($frontier);

            foreach ($plan['batches'] as $batch)
            {
                $this->assertGreaterThan(1, count($batch));
                $output = $provider->reduce($this->criterion, $batch, $segments, $context, 16384);
                $node = $provider->nodeFromReduction($this->reverseObjectKeys(['observations' => $output->observations, 'metadata' => $output->metadata]));
                $this->assertSame(array_column($batch, 'id'), $node['child_ids']);
                $this->assertSame(array_merge(...array_column($batch, 'source_ids')), $node['source_ids']);
                $this->assertContains('supporting', $node['flags']['statuses']);
                $this->assertContains('contradicting', $node['flags']['statuses']);
                $this->assertCount(count($batch), $node['evidence_ledger']);
                $offset = array_search($node['child_ids'][0], array_column($frontier, 'id'), true);
                array_splice($frontier, $offset, count($batch), [$node]);
                $reductions++;
            }
            $this->assertLessThan($previousCount, count($frontier));
        }

        $this->assertGreaterThan(0, $reductions);
        $this->assertSame('reconcile', $plan['kind']);
        $result = $provider->reconcileNodes($this->criterion, $frontier, $segments, $context, 16384);

        $this->assertSame('FAIL', $result->results[0]['decision']);
        $this->assertSame('whole_call_reconciliation', $result->metadata['scope']);
        $this->assertSame($context['reconciliation_source_ids'], $result->metadata['reconciliation_source_ids']);
        $this->assertCount(12, $result->metadata['evidence_ledger']);
        $this->assertFalse($result->metadata['lossless_reconciliation']);
        Http::assertSentCount(($reductions + 1) * 2);
    }

    public function test_a_reduction_cannot_discard_original_contradictory_references(): void
    {
        [$segments, $findings] = $this->fixture(2, 50);
        $provider = app(EvaluationProvider::class);
        $context = $this->context($segments);
        $nodes = $provider->reconciliationNodes($this->criterion, $findings, $segments, $context);
        $this->fakeResponse(['observations' => [$this->observation('supporting', $segments[0]['id'])]]);
        $this->expectExceptionMessage('discarded supporting or contradictory');

        $provider->reduce($this->criterion, $nodes, $segments, $context, 16384);
    }

    public function test_a_reduction_cannot_erase_original_attribution_uncertainty(): void
    {
        [$segments, $findings] = $this->fixture(2, 50);
        $findings[0]['observations'][0] = [...$findings[0]['observations'][0], 'status' => 'uncertain', 'uncertainty' => 'The speaker is unidentified.', 'needs_context' => true];
        $provider = app(EvaluationProvider::class);
        $context = $this->context($segments);
        $nodes = $provider->reconciliationNodes($this->criterion, $findings, $segments, $context);
        $summary = $this->observation('mixed', $segments[0]['id']);
        $summary['evidence'][] = ['segment_id' => $segments[1]['id'], 'role' => 'contradicting'];
        $this->fakeResponse(['observations' => [$summary]]);
        $this->expectExceptionMessage('discarded an unresolved uncertainty');

        $provider->reduce($this->criterion, $nodes, $segments, $context, 16384);
    }

    public function test_unanalysed_original_bytes_prevent_a_whole_call_judgment(): void
    {
        [$segments, $findings] = $this->fixture(1, 50);
        $findings[0]['metadata']['primary_spans'][0]['text_end']--;
        $this->expectExceptionMessage('has not been analyzed');

        app(EvaluationProvider::class)->reconciliationNodes($this->criterion, $findings, $segments, $this->context($segments));
    }

    public function test_duplicate_original_window_sources_are_rejected(): void
    {
        [$segments, $findings] = $this->fixture(1, 50);
        $provider = app(EvaluationProvider::class);
        $context = $this->context($segments);
        $nodes = $provider->reconciliationNodes($this->criterion, $findings, $segments, $context);
        $context['reconciliation_source_ids'] = [$nodes[0]['id'], $nodes[0]['id']];
        $this->expectException(ProcessingException::class);

        $provider->planReconciliation($this->criterion, [$nodes[0], $nodes[0]], $segments, $context, 16384);
    }

    public function test_a_changed_source_frontier_is_rejected_before_inference(): void
    {
        [$segments, $findings] = $this->fixture(2, 50);
        $provider = app(EvaluationProvider::class);
        $context = $this->context($segments);
        $nodes = $provider->reconciliationNodes($this->criterion, $findings, $segments, $context);
        $context['reconciliation_source_ids'] = array_reverse(array_column($nodes, 'id'));
        $this->expectExceptionMessage('every original window source exactly once');

        $provider->planReconciliation($this->criterion, $nodes, $segments, $context, 16384);
    }

    public function test_oversized_original_segments_use_their_actual_analysed_byte_spans_in_bounded_excerpts(): void
    {
        $segments = [['id' => 101, 'start' => 0.0, 'end' => 30.0, 'text' => str_repeat('a', 14000), 'speaker' => null]];
        $findings = [];

        foreach ([[0, 7000], [7000, 14000]] as $index => [$start, $end])
        {
            $span = ['segment_id' => 101, 'text_start' => $start, 'text_end' => $end];
            $findings[] = ['observations' => [$this->observation($index === 0 ? 'supporting' : 'contradicting', 101)], 'metadata' => ['primary_spans' => [$span], 'analyzed_spans' => [$span], 'source_step_key' => 'window-'.$index]];
        }
        $provider = app(EvaluationProvider::class);
        $context = $this->context($segments);
        $nodes = $provider->reconciliationNodes($this->criterion, $findings, $segments, $context);
        $context['reconciliation_source_ids'] = array_column($nodes, 'id');
        $this->fakeInference();
        $result = $provider->reconcileNodes($this->criterion, $nodes, $segments, $context, 16384);

        $this->assertTrue($result->metadata['bounded_evidence_excerpts']);
        Http::assertSent(function (Request $request) use ($segments)
        {
            if (!str_ends_with($request->url(), '/api/chat'))
            {
                return false;
            }
            $data = json_decode($request['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame([0, 7000], array_column($data['excerpts'], 3));

            foreach ($data['excerpts'] as $excerpt)
            {
                $this->assertSame(substr($segments[0]['text'], $excerpt[3], $excerpt[4] - $excerpt[3]), $excerpt[5]);
                $this->assertLessThanOrEqual(256, strlen($excerpt[5]));
            }

            return true;
        });
    }

    private function fixture(int $count, int $explanationLength): array
    {
        $segments = [];
        $findings = [];

        for ($index = 0; $index < $count; $index++)
        {
            $id = 101 + $index;
            $segment = ['id' => $id, 'start' => (float) ($index * 10), 'end' => (float) (($index + 1) * 10), 'text' => 'Original conversation segment '.$index.'.', 'speaker' => null];
            $span = ['segment_id' => $id, 'text_start' => 0, 'text_end' => strlen($segment['text'])];
            $observation = $this->observation($index % 2 === 0 ? 'supporting' : 'contradicting', $id);
            $observation['explanation'] = str_repeat('x', $explanationLength);
            $segments[] = $segment;
            $findings[] = ['observations' => [$observation], 'metadata' => ['primary_spans' => [$span], 'analyzed_spans' => [$span], 'source_step_key' => 'window-'.$index]];
        }

        return [$segments, $findings];
    }

    private function context(array $segments): array
    {
        return ['is_complete' => true, 'duration' => $segments[array_key_last($segments)]['end'], 'model_digest' => 'test-model-digest'];
    }

    private function observation(string $status, int $segmentId): array
    {
        return [
            'criterion_id' => 11, 'status' => $status,
            'evidence' => [['segment_id' => $segmentId, 'role' => $status === 'contradicting' ? 'contradicting' : 'supporting']],
            'explanation' => 'A partial finding.', 'uncertainty' => null, 'needs_context' => false, 'applicability' => 'applicable',
        ];
    }

    private function fakeInference(): void
    {
        Http::fake(function (Request $request)
        {
            if (str_ends_with($request->url(), '/api/tags'))
            {
                return Http::response(['models' => [['name' => 'qwen3:14b-q4_K_M', 'digest' => 'test-model-digest']]]);
            }
            $data = json_decode($request['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR);
            $evidence = [];

            foreach ($data['nodes'] as $node)
            {
                foreach ($node[2] as [$id, $role])
                {
                    $evidence[$role] ??= ['segment_id' => $id, 'role' => $role];
                }
            }

            if ($data['scope'] === 'partial_reduction')
            {
                $observation = $this->observation(isset($evidence['supporting'], $evidence['contradicting']) ? 'mixed' : array_key_first($evidence), $evidence[array_key_first($evidence)]['segment_id']);
                $observation['evidence'] = array_values($evidence);
                $payload = ['observations' => [$observation]];
            }
            else
            {
                $payload = ['results' => [[
                    'criterion_id' => 11, 'decision' => 'FAIL', 'evidence_basis' => 'contradictory',
                    'explanation' => 'The later contradictory conduct prevents this standard from passing.',
                    'uncertainty' => null, 'evidence' => [$evidence['contradicting']],
                ]]];
            }

            return Http::response(['done' => true, 'done_reason' => 'stop', 'message' => ['content' => json_encode($payload, JSON_THROW_ON_ERROR)]]);
        });
    }

    private function fakeResponse(array $payload): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:14b-q4_K_M', 'digest' => 'test-model-digest']]]),
            '*/api/chat' => Http::response(['done' => true, 'done_reason' => 'stop', 'message' => ['content' => json_encode($payload, JSON_THROW_ON_ERROR)]]),
        ]);
    }

    private function reverseObjectKeys(array $value): array
    {
        if (!array_is_list($value))
        {
            krsort($value);
        }

        return array_map(fn (mixed $item) => is_array($item) ? $this->reverseObjectKeys($item) : $item, $value);
    }
}
