<?php

namespace App\Services;

use App\Actions\PersistEvaluation;
use App\AI\CheckpointCompatibility;
use App\AI\Contracts\EvaluationProvider;
use App\AI\Data\EvaluationResult;
use App\AI\Exceptions\MemoryExhaustedException;
use App\AI\Exceptions\ProcessingException;
use App\AI\Exceptions\TransientEvaluationException;
use App\AI\TranscriptWindowOrder;
use App\Jobs\EvaluateCall;
use App\Models\Evaluation;
use App\Models\EvaluationRun;
use App\Models\EvaluationStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class WindowedEvaluationWorkflow
{
    private const PROMPT_VERSION = 'qa-windowed-v3';

    public function __construct(
        private EvaluationProvider $provider,
        private PersistEvaluation $persist,
        private CheckpointCompatibility $checkpoints,
        private TranscriptWindowOrder $windowOrder,
    ) {
    }

    public function advance(int $evaluationId): void
    {
        $evaluation = Evaluation::findOrFail($evaluationId);

        if (in_array($evaluation->status, ['completed', 'failed'], true) || $evaluation->results()->exists())
        {
            return;
        }
        $run = null;
        $step = null;
        $checkpointCommitted = false;

        try
        {
            $run = $this->prepare($evaluation);
            $this->planRemaining($run);
            $step = $this->claim($evaluationId, $run->id);

            if (!$step)
            {
                if (!$run->steps()->whereIn('status', ['pending', 'running', 'failed'])->exists())
                {
                    $this->finalize($evaluationId, $run);
                }

                return;
            }
            $context = $this->context($run);

            if ($step->kind === 'window')
            {
                $criteria = array_values(array_filter($run->criteria_snapshot, fn ($criterion) => in_array($criterion['id'], $step->criterion_ids, true)));
                $output = $this->provider->inspect($criteria, $step->payload['spans'], $context, $step->context_tokens);
                $saved = ['observations' => $output->observations, 'metadata' => $output->metadata];
            }
            elseif ($step->kind === 'reduce')
            {
                $criterion = collect($run->criteria_snapshot)->firstWhere('id', $step->criterion_ids[0]);
                $context = $this->reconciliationContext($run, $criterion);
                $output = $this->provider->reduce($criterion, $step->payload['nodes'], $run->segments_snapshot, $context, $step->context_tokens);
                $saved = ['observations' => $output->observations, 'metadata' => $output->metadata];
            }
            else
            {
                $criterion = collect($run->criteria_snapshot)->firstWhere('id', $step->criterion_ids[0]);
                $context = $this->reconciliationContext($run, $criterion);
                $output = isset($step->payload['nodes'])
                    ? $this->provider->reconcileNodes($criterion, $step->payload['nodes'], $run->segments_snapshot, $context, $step->context_tokens)
                    : $this->provider->reconcileNodes($criterion, $this->provider->reconciliationNodes($criterion, $this->findings($run, $criterion['id']), $run->segments_snapshot, $context), $run->segments_snapshot, $context, $step->context_tokens);
                $saved = ['results' => $output->results, 'metadata' => $output->metadata];
            }
            DB::transaction(function () use ($evaluationId, $run, $step, $saved, &$checkpointCommitted)
            {
                $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($evaluationId);
                $current = EvaluationStep::query()->lockForUpdate()->findOrFail($step->id);

                if ($current->status !== 'running' || $current->attempt_token !== $step->attempt_token || $evaluation->attempt_token !== $step->attempt_token || !EvaluationRun::whereKey($run->id)->where('status', 'running')->exists())
                {
                    return;
                }
                $current->update(['status' => 'completed', 'output' => $saved, 'completed_at' => now(), 'attempt_token' => null, 'error_message' => null]);
                $evaluation->update(['attempt_token' => null, 'started_at' => now()]);
                $run->touch();
                DB::afterCommit(function () use ($evaluationId, &$checkpointCommitted)
                {
                    $checkpointCommitted = true;
                    EvaluateCall::dispatch($evaluationId)->onQueue('audio');
                });
            });
        }
        catch (MemoryExhaustedException $exception)
        {
            if ($run && $step && $run->context_tokens > 4096)
            {
                $this->downgrade($evaluationId, $run, $step, $exception->getMessage());

                return;
            }
            $this->fail($evaluationId, $run, $step, 'Qwen could not allocate enough memory at the smallest 4K context. Free GPU/system memory and retry; saved transcript and checkpoints are intact.');
        }
        catch (TransientEvaluationException $exception)
        {
            if ($run && $step && $step->attempts < 3)
            {
                $delay = $step->attempts === 1 ? 30 : 90;
                DB::transaction(function () use ($evaluationId, $run, $step, $exception, $delay)
                {
                    $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($evaluationId);
                    $current = EvaluationStep::query()->lockForUpdate()->findOrFail($step->id);

                    if ($current->status !== 'running' || $current->attempt_token !== $step->attempt_token || $evaluation->attempt_token !== $step->attempt_token || !EvaluationRun::whereKey($run->id)->where('status', 'running')->exists())
                    {
                        return;
                    }
                    $current->update(['status' => 'pending', 'attempt_token' => null, 'next_attempt_at' => now()->addSeconds($delay), 'error_message' => $exception->getMessage()]);
                    $evaluation->update(['attempt_token' => null, 'error_message' => null, 'started_at' => now()]);
                    EvaluateCall::dispatch($evaluationId)->onQueue('audio')->delay(now()->addSeconds($delay))->afterCommit();
                });

                return;
            }
            $this->fail($evaluationId, $run, $step, $exception->getMessage());
        }
        catch (Throwable $exception)
        {
            if ($checkpointCommitted)
            {
                // Retry only continuation delivery; the committed model output is immutable and reused.
                throw $exception;
            }
            $message = $exception instanceof ProcessingException ? $exception->getMessage() : 'Local evaluation stopped unexpectedly. Retry to resume saved checkpoints; inspect the local log if the problem continues.';
            report($exception);
            $this->fail($evaluationId, $run, $step, $message);
        }
    }

    private function prepare(Evaluation $evaluation): EvaluationRun
    {
        $evaluation->load(['templateVersion.criteria', 'call.segments']);
        $criteria = $evaluation->templateVersion->criteria->map(fn ($criterion) => $criterion->only(['id', 'title', 'question', 'instructions', 'pass_standard', 'applicability', 'weight', 'required', 'scoring_type']))->values()->all();
        $segments = $evaluation->call->segments->map(fn ($segment) => $segment->only(['id', 'start', 'end', 'text', 'speaker']))->values()->all();

        if ($segments === [] || $criteria === [])
        {
            throw new ProcessingException('Evaluation requires a saved transcript and published criteria.');
        }
        $digest = $this->provider->modelDigest();
        $context = ['is_complete' => $evaluation->call->is_complete, 'duration' => $evaluation->call->duration, 'call_id' => $evaluation->call->id, 'evaluation_provider' => $this->provider::class];
        $sourceHashes = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('AI'), \FilesystemIterator::SKIP_DOTS)) as $source)
        {
            if ($source->getExtension() === 'php')
            {
                $sourceHashes[$source->getPathname()] = hash_file('sha256', $source->getPathname());
            }
        }
        ksort($sourceHashes);
        $settings = array_intersect_key(config('qa.ollama'), array_flip(['model', 'temperature', 'top_p', 'top_k', 'min_p']));
        $fingerprint = hash('sha256', json_encode([$criteria, $segments, $context, $digest, self::PROMPT_VERSION, $settings, $sourceHashes], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($evaluation, $criteria, $segments, $context, $digest, $fingerprint)
        {
            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($evaluation->id);
            EvaluationRun::where('evaluation_id', $evaluation->id)->where('fingerprint', '!=', $fingerprint)->whereIn('status', ['running', 'failed'])->update(['status' => 'superseded']);
            $run = EvaluationRun::firstOrCreate(['evaluation_id' => $evaluation->id, 'fingerprint' => $fingerprint], ['model_digest' => $digest, 'prompt_version' => self::PROMPT_VERSION, 'criteria_snapshot' => $criteria, 'segments_snapshot' => $segments, 'context' => $context, 'context_tokens' => 16384, 'plan_revision' => 1, 'status' => 'running']);

            if ($run->wasRecentlyCreated)
            {
                $this->reuseCheckpoints($run);
            }

            if (in_array($run->status, ['failed', 'superseded'], true))
            {
                $run->steps()->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'attempt_token' => null, 'next_attempt_at' => null, 'error_message' => null]);
                $run->update(['status' => 'running', 'error_message' => null]);
            }

            return $run;
        });
    }

    private function planRemaining(EvaluationRun $run): void
    {
        if ($run->steps()->whereIn('status', ['pending', 'running'])->exists())
        {
            return;
        }
        $coverage = $this->coverage($run);
        $plans = [];

        if (!$run->steps()->exists())
        {
            $plans = $this->provider->plan($run->criteria_snapshot, $run->segments_snapshot, $this->context($run), $run->context_tokens);
        }
        else
        {
            foreach ($run->criteria_snapshot as $criterion)
            {
                $remaining = $this->remaining($run->segments_snapshot, $coverage[$criterion['id']] ?? []);

                if ($remaining !== [])
                {
                    foreach ($this->windowOrder->groups($remaining, $run->segments_snapshot) as $group)
                    {
                        array_push($plans, ...$this->provider->plan([$criterion], $group, $this->context($run), $run->context_tokens));
                    }
                }
            }
        }
        foreach ($plans as $payload)
        {
            $this->createStep($run, 'window', $payload['criterion_ids'], $payload);
        }

        if ($plans !== [])
        {
            return;
        }
        foreach ($run->criteria_snapshot as $criterion)
        {
            if ($run->steps()->where('kind', 'reconcile')->where('status', 'completed')->get()->contains(fn ($step) => $step->criterion_ids === [$criterion['id']]))
            {
                continue;
            }

            $context = $this->context($run);
            $nodes = $this->provider->reconciliationNodes($criterion, $this->findings($run, $criterion['id']), $run->segments_snapshot, $context);
            $context['reconciliation_source_ids'] = array_column($nodes, 'id');
            $frontier = $this->reconciliationFrontier($run, $criterion['id'], $nodes);
            $plan = $this->provider->planReconciliation($criterion, $frontier, $run->segments_snapshot, $context, $run->context_tokens);

            if ($plan['kind'] === 'reconcile')
            {
                $this->createStep($run, 'reconcile', [$criterion['id']], ['criterion_id' => $criterion['id'], 'nodes' => $plan['nodes']]);

                continue;
            }

            foreach ($plan['batches'] as $batch)
            {
                $this->createStep($run, 'reduce', [$criterion['id']], ['criterion_id' => $criterion['id'], 'nodes' => $batch]);
            }
        }
    }

    private function reuseCheckpoints(EvaluationRun $target): void
    {
        $sources = EvaluationRun::where('evaluation_id', $target->evaluation_id)->whereKeyNot($target->id)->latest('id')->get();

        foreach ($sources as $source)
        {
            if (!$this->checkpoints->sameInputs($source, $target))
            {
                continue;
            }

            foreach ($source->steps()->where('kind', 'window')->where('status', 'completed')->get() as $step)
            {
                if ($this->checkpoints->window($step, $source, $target))
                {
                    $this->copyCheckpoint($target, $step);
                }
            }

            foreach ($source->steps()->where('kind', 'reconcile')->where('status', 'completed')->get() as $step)
            {
                $criterionId = $step->criterion_ids[0];

                if ($this->checkpoints->reconciliation($step, $source, $target, $this->findings($target, $criterionId), $this->context($target)))
                {
                    $this->copyCheckpoint($target, $step);
                }
            }

            return;
        }
    }

    private function copyCheckpoint(EvaluationRun $target, EvaluationStep $source): void
    {
        $output = $source->output;
        $output['metadata'] = [...$output['metadata'], 'reused_from_step_id' => $source->id, 'reused_from_run_id' => $source->evaluation_run_id];
        $target->steps()->firstOrCreate(['step_key' => $source->step_key], [
            'kind' => $source->kind, 'status' => 'completed', 'plan_revision' => $target->plan_revision,
            'context_tokens' => $source->context_tokens, 'criterion_ids' => $source->criterion_ids,
            'payload' => $source->payload, 'output' => $output, 'attempts' => 0,
            'started_at' => $source->started_at, 'completed_at' => $source->completed_at,
        ]);
    }

    private function reconciliationContext(EvaluationRun $run, array $criterion): array
    {
        $context = $this->context($run);
        $nodes = $this->provider->reconciliationNodes($criterion, $this->findings($run, $criterion['id']), $run->segments_snapshot, $context);

        return [...$context, 'reconciliation_source_ids' => array_column($nodes, 'id')];
    }

    private function reconciliationFrontier(EvaluationRun $run, int $criterionId, array $nodes): array
    {
        foreach ($run->steps()->where('kind', 'reduce')->where('status', 'completed')->get() as $step)
        {
            if ($step->criterion_ids !== [$criterionId])
            {
                continue;
            }

            $node = $this->provider->nodeFromReduction($step->output);
            $children = $node['child_ids'];
            $ids = array_column($nodes, 'id');
            $offset = array_search($children[0], $ids, true);

            if ($offset === false || array_slice($ids, $offset, count($children)) !== $children)
            {
                throw new ProcessingException('Saved reconciliation batches do not match the chronological source ledger. No score was saved.');
            }

            array_splice($nodes, $offset, count($children), [$node]);
        }

        return $nodes;
    }

    private function createStep(EvaluationRun $run, string $kind, array $criterionIds, array $payload): void
    {
        $key = hash('sha256', json_encode([$kind, $criterionIds, $payload, $run->context_tokens, $run->plan_revision], JSON_THROW_ON_ERROR));
        $run->steps()->firstOrCreate(['step_key' => $key], ['kind' => $kind, 'status' => 'pending', 'plan_revision' => $run->plan_revision, 'context_tokens' => $run->context_tokens, 'criterion_ids' => $criterionIds, 'payload' => $payload]);
    }

    private function claim(int $evaluationId, int $runId): ?EvaluationStep
    {
        return DB::transaction(function () use ($evaluationId, $runId)
        {
            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($evaluationId);
            $run = EvaluationRun::query()->lockForUpdate()->findOrFail($runId);

            if (in_array($evaluation->status, ['completed', 'failed'], true) || $run->status !== 'running' || $run->steps()->where('status', 'running')->exists())
            {
                return null;
            }
            $step = $run->steps()->where('status', 'pending')->lockForUpdate()->first();

            if (!$step)
            {
                return null;
            }

            if ($step->next_attempt_at?->isFuture())
            {
                EvaluateCall::dispatch($evaluationId)->onQueue('audio')->delay($step->next_attempt_at)->afterCommit();

                return null;
            }
            $token = (string) Str::uuid();
            $step->update(['status' => 'running', 'attempts' => $step->attempts + 1, 'attempt_token' => $token, 'started_at' => now(), 'next_attempt_at' => null]);
            $evaluation->update(['status' => 'evaluating', 'attempt_token' => $token, 'started_at' => now(), 'error_message' => null]);
            $evaluation->call->update(['status' => 'evaluating', 'error_message' => null]);

            return $step;
        });
    }

    private function coverage(EvaluationRun $run): array
    {
        $coverage = [];

        foreach ($run->steps()->where('kind', 'window')->where('status', 'completed')->get() as $step)
        {
            foreach ($step->criterion_ids as $criterionId)
            {
                foreach ($step->payload['primary_spans'] as $span)
                {
                    $coverage[$criterionId][] = $span;
                }
            }
        }

        return $coverage;
    }

    private function remaining(array $segments, array $coverage): array
    {
        $remaining = [];

        foreach ($segments as $segment)
        {
            $ranges = array_values(array_filter($coverage, fn ($span) => $span['segment_id'] === $segment['id']));
            usort($ranges, fn ($left, $right) => $left['text_start'] <=> $right['text_start']);
            $cursor = 0;
            $length = strlen($segment['text']);

            foreach ($ranges as $range)
            {
                if ($range['text_start'] > $cursor)
                {
                    $remaining[] = [...$segment, 'text' => substr($segment['text'], $cursor, $range['text_start'] - $cursor), 'text_start' => $cursor, 'text_end' => $range['text_start']];
                }
                $cursor = max($cursor, $range['text_end']);
            }

            if ($cursor < $length)
            {
                $remaining[] = [...$segment, 'text' => substr($segment['text'], $cursor), 'text_start' => $cursor, 'text_end' => $length];
            }
        }

        return $remaining;
    }

    private function context(EvaluationRun $run): array
    {
        $lengths = [];

        foreach ($run->segments_snapshot as $segment)
        {
            $lengths[$segment['id']] = strlen($segment['text']);
        }

        return [...$run->context, 'model_digest' => $run->model_digest, 'expected_segment_ids' => array_column($run->segments_snapshot, 'id'), 'expected_span_lengths' => $lengths, 'window_coverage' => $this->coverage($run)];
    }

    private function findings(EvaluationRun $run, int $criterionId): array
    {
        $records = $run->steps()->where('kind', 'window')->where('status', 'completed')->get()->filter(fn ($step) => in_array($criterionId, $step->criterion_ids, true))->map(function ($step)
        {
            $output = $step->output;
            $spans = array_map(fn (array $span) => ['segment_id' => $span['id'], 'text_start' => $span['text_start'], 'text_end' => $span['text_end'], 'overlap' => $span['overlap']], $step->payload['spans']);
            $output['metadata'] = [...$output['metadata'], 'source_step_key' => $step->step_key, 'analyzed_spans' => $spans];

            return $output;
        })->values()->all();

        return $this->windowOrder->sort($records, $run->segments_snapshot);
    }

    private function downgrade(int $evaluationId, EvaluationRun $run, EvaluationStep $step, string $message): void
    {
        DB::transaction(function () use ($evaluationId, $run, $step, $message)
        {
            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($evaluationId);
            $run = EvaluationRun::query()->lockForUpdate()->findOrFail($run->id);
            $current = EvaluationStep::query()->lockForUpdate()->findOrFail($step->id);

            if ($current->status !== 'running' || $current->attempt_token !== $step->attempt_token || $evaluation->attempt_token !== $step->attempt_token || $run->status !== 'running')
            {
                return;
            }
            $current->update(['status' => 'superseded', 'attempt_token' => null, 'error_message' => $message]);
            $run->steps()->where('status', 'pending')->update(['status' => 'superseded']);
            $run->update(['context_tokens' => $run->context_tokens === 16384 ? 8192 : 4096, 'plan_revision' => $run->plan_revision + 1, 'error_message' => null]);
            $evaluation->update(['attempt_token' => null, 'started_at' => now()]);
            EvaluateCall::dispatch($evaluationId)->onQueue('audio')->delay(now()->addSeconds(15))->afterCommit();
        });
    }

    private function fail(int $evaluationId, ?EvaluationRun $run, ?EvaluationStep $step, string $message): void
    {
        DB::transaction(function () use ($evaluationId, $run, $step, $message)
        {
            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($evaluationId);

            if ($evaluation->status === 'completed' || ($step && $evaluation->attempt_token !== $step->attempt_token) || (!$step && $evaluation->attempt_token !== null) || ($run && !EvaluationRun::whereKey($run->id)->where('status', 'running')->exists()))
            {
                return;
            }

            if ($step)
            {
                EvaluationStep::whereKey($step->id)->where('status', 'running')->where('attempt_token', $step->attempt_token)->update(['status' => 'failed', 'attempt_token' => null, 'error_message' => $message]);
            }
            $run?->update(['status' => 'failed', 'error_message' => $message]);
            $evaluation->update(['status' => 'failed', 'attempt_token' => null, 'error_message' => $message]);
            $evaluation->call->update(['status' => 'failed', 'error_message' => $message]);
        });
    }

    private function finalize(int $evaluationId, EvaluationRun $run): void
    {
        $coverage = $this->coverage($run);

        foreach ($run->criteria_snapshot as $criterion)
        {
            if ($this->remaining($run->segments_snapshot, $coverage[$criterion['id']] ?? []) !== [])
            {
                throw new ProcessingException('The transcript coverage ledger is incomplete. No score was saved.');
            }
        }
        $steps = $run->steps()->where('kind', 'reconcile')->where('status', 'completed')->get();
        $results = $steps->flatMap(fn ($step) => $step->output['results'])->values()->all();

        if (count($results) !== count($run->criteria_snapshot))
        {
            throw new ProcessingException('Whole-call reconciliation is incomplete. Saved checkpoints are intact.');
        }
        $completed = $run->steps()->where('status', 'completed')->get();
        $metadata = ['evaluation_provider' => $this->provider::class, ...array_intersect_key($completed->last()->output['metadata'], array_flip(['provider', 'model'])), 'model_digest' => $run->model_digest, 'prompt_version' => self::PROMPT_VERSION, 'evaluation_run_id' => $run->id, 'context_tokens' => $run->context_tokens, 'plan_revisions' => $run->plan_revision, 'windows_completed' => $completed->where('kind', 'window')->count(), 'reduction_batches_completed' => $completed->where('kind', 'reduce')->count(), 'reused_checkpoints' => $completed->filter(fn ($step) => isset($step->output['metadata']['reused_from_step_id']))->count(), 'reconciliations_completed' => $steps->count(), 'thinking' => false, 'elapsed_seconds' => round($completed->sum(fn ($step) => $step->output['metadata']['elapsed_seconds'] ?? 0), 3), 'steps' => $completed->map(fn ($step) => ['id' => $step->id, 'kind' => $step->kind, 'context_tokens' => $step->context_tokens, 'metadata' => $step->output['metadata']])->all()];
        DB::transaction(function () use ($evaluationId, $run, $results, $metadata)
        {
            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($evaluationId);

            $run = EvaluationRun::query()->lockForUpdate()->findOrFail($run->id);

            if (in_array($evaluation->status, ['completed', 'failed'], true) || $evaluation->attempt_token !== null || $run->status !== 'running' || $run->steps()->whereIn('status', ['pending', 'running', 'failed'])->exists())
            {
                return;
            }
            $token = (string) Str::uuid();
            $evaluation->update(['status' => 'evaluating', 'attempt_token' => $token]);
            $this->persist->handle($evaluationId, $token, new EvaluationResult($results, $metadata));
            $run->update(['status' => 'completed', 'completed_at' => now(), 'error_message' => null]);
        });
    }

    public function progress(Evaluation $evaluation): ?array
    {
        $run = EvaluationRun::where('evaluation_id', $evaluation->id)->where('status', '!=', 'superseded')->latest('id')->first();

        if (!$run)
        {
            return null;
        }
        $steps = $run->steps()->where('status', '!=', 'superseded')->get();
        $windows = $steps->where('kind', 'window');
        $completed = $windows->where('status', 'completed')->count();
        $total = $windows->count();
        $phase = $total === 0 ? 'planning' : ($completed < $total ? 'windows' : 'reconciling');
        $reconciled = $steps->where('kind', 'reconcile')->where('status', 'completed')->count();
        $reductions = $steps->where('kind', 'reduce');
        $combined = $reductions->where('status', 'completed')->count();
        $active = $steps->firstWhere('status', 'running');
        $combining = $active ? $active->kind === 'reduce' : $reductions->where('status', 'pending')->isNotEmpty();
        $message = match ($phase)
        {
            'planning' => 'Preparing bounded context windows.',
            'windows' => 'Analysing window '.min($completed + 1, $total).' of '.$total.'.',
            default => $combining
                ? 'Combining evidence batch '.min($combined + 1, $reductions->count()).' of '.$reductions->count().'.'
                : 'Reconciling whole-call criterion '.min($reconciled + 1, count($run->criteria_snapshot)).' of '.count($run->criteria_snapshot).'.',
        };

        return compact('phase', 'completed', 'total', 'message') + ['context_tokens' => $run->context_tokens];
    }

    public function recover(Evaluation $evaluation): bool
    {
        return DB::transaction(function () use ($evaluation)
        {
            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($evaluation->id);
            $run = EvaluationRun::where('evaluation_id', $evaluation->id)->where('status', 'running')->latest('id')->first();

            if (!$run || !in_array($evaluation->status, ['queued', 'evaluating'], true))
            {
                return false;
            }
            $active = $run->steps()->where('status', 'running')->lockForUpdate()->first();

            if ($active && $active->started_at->greaterThan(now()->subMinutes(40)))
            {
                return false;
            }

            if ($active && $active->attempts >= 3)
            {
                $message = 'The same model request was interrupted three times. Retry manually to resume saved checkpoints.';
                $active->update(['status' => 'failed', 'attempt_token' => null, 'error_message' => $message]);
                $run->update(['status' => 'failed', 'error_message' => $message]);
                $evaluation->update(['status' => 'failed', 'attempt_token' => null, 'error_message' => $message]);
                $evaluation->call->update(['status' => 'failed', 'error_message' => $message]);

                return true;
            }

            if ($active)
            {
                $active->update(['status' => 'pending', 'attempt_token' => null, 'next_attempt_at' => null, 'error_message' => 'Interrupted request; resuming this saved step.']);
            }
            $evaluation->update(['status' => 'queued', 'attempt_token' => null, 'error_message' => null]);
            EvaluateCall::dispatch($evaluation->id)->onQueue('audio')->afterCommit();

            return true;
        });
    }
}
