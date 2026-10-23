<?php

namespace Tests\Feature;

use App\AI\Contracts\EvaluationProvider;
use App\AI\Data\WindowResult;
use App\Jobs\EvaluateCall;
use App\Jobs\TranscribeCall;
use App\Models\CallRecording;
use App\Models\Evaluation;
use App\Models\EvaluationRun;
use App\Models\EvaluationStep;
use App\Models\QaTemplate;
use App\Models\User;
use App\Services\WindowedEvaluationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class WindowedRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_a_queued_retry_with_only_a_failed_historical_run_is_redelivered_using_its_transcript(): void
    {
        $evaluation = $this->evaluation();
        $segment = $evaluation->call->segments()->firstOrFail();
        $this->evaluationRun($evaluation, 'failed');

        $this->artisan('qa:recover')->assertSuccessful();

        Queue::assertPushed(EvaluateCall::class, fn ($job) => $job->evaluationId === $evaluation->id);
        Queue::assertNotPushed(TranscribeCall::class);
        $this->assertSame('queued', $evaluation->fresh()->status);
        $this->assertDatabaseHas('transcript_segments', ['id' => $segment->id, 'text' => $segment->text]);
    }

    public function test_recent_active_step_protects_an_old_evaluation_from_recovery(): void
    {
        $evaluation = $this->evaluation('evaluating');
        $evaluation->update(['started_at' => now()->subHours(3), 'attempt_token' => $token = (string) Str::uuid()]);
        $run = $this->evaluationRun($evaluation);
        $step = $this->step($run, 'running', ['started_at' => now()->subMinutes(10), 'attempt_token' => $token, 'attempts' => 1]);

        $this->assertFalse(app(WindowedEvaluationWorkflow::class)->recover($evaluation));

        $this->assertSame('running', $step->fresh()->status);
        $this->assertSame($token, $evaluation->fresh()->attempt_token);
        Queue::assertNothingPushed();
    }

    public function test_interrupted_step_is_requeued_without_changing_completed_checkpoints(): void
    {
        $evaluation = $this->evaluation('evaluating');
        $evaluation->update(['attempt_token' => $token = (string) Str::uuid()]);
        $run = $this->evaluationRun($evaluation);
        $completed = $this->step($run, 'completed', ['output' => ['observations' => [], 'metadata' => ['checkpoint' => 'preserve-this']], 'completed_at' => now()->subHour()]);
        $before = $completed->fresh()->getRawOriginal('output');
        $active = $this->step($run, 'running', ['started_at' => now()->subMinutes(41), 'attempt_token' => $token, 'attempts' => 1]);

        $this->assertTrue(app(WindowedEvaluationWorkflow::class)->recover($evaluation));

        $this->assertSame('pending', $active->fresh()->status);
        $this->assertSame(1, $active->fresh()->attempts);
        $this->assertNull($active->fresh()->attempt_token);
        $this->assertSame('queued', $evaluation->fresh()->status);
        $this->assertSame($before, $completed->fresh()->getRawOriginal('output'));
        Queue::assertPushed(EvaluateCall::class, fn ($job) => $job->evaluationId === $evaluation->id);
    }

    public function test_three_interruptions_stop_visibly_without_a_fourth_automatic_attempt(): void
    {
        $evaluation = $this->evaluation('evaluating');
        $evaluation->update(['attempt_token' => $token = (string) Str::uuid()]);
        $run = $this->evaluationRun($evaluation);
        $active = $this->step($run, 'running', ['started_at' => now()->subMinutes(41), 'attempt_token' => $token, 'attempts' => 3]);

        $this->assertTrue(app(WindowedEvaluationWorkflow::class)->recover($evaluation));

        $this->assertSame('failed', $active->fresh()->status);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('failed', $evaluation->fresh()->status);
        $this->assertStringContainsString('interrupted three times', $evaluation->fresh()->error_message);
        Queue::assertNothingPushed();
    }

    public function test_uploaded_call_workflow_uses_the_configured_phase_provider(): void
    {
        $evaluation = $this->evaluation();
        $segment = $evaluation->call->segments()->firstOrFail();
        $criterion = $evaluation->templateVersion->criteria()->firstOrFail();
        $span = [...$segment->only(['id', 'start', 'end', 'text', 'speaker']), 'text_start' => 0, 'text_end' => strlen($segment->text), 'overlap' => false];
        $primary = ['segment_id' => $segment->id, 'text_start' => 0, 'text_end' => strlen($segment->text)];
        $provider = Mockery::mock(EvaluationProvider::class);
        $provider->shouldReceive('modelDigest')->once()->andReturn('configured-provider-digest');
        $provider->shouldReceive('plan')->once()->andReturn([[
            'criterion_ids' => [$criterion->id], 'spans' => [$span], 'primary_spans' => [$primary],
            'primary_segment_ids' => [$segment->id], 'context_tokens' => 16384,
        ]]);
        $provider->shouldReceive('inspect')->once()->andReturn(new WindowResult([[
            'criterion_id' => $criterion->id, 'status' => 'supporting',
            'evidence' => [['segment_id' => $segment->id, 'role' => 'supporting']],
            'explanation' => 'The agent identifies the bank.', 'uncertainty' => null,
            'needs_context' => false, 'applicability' => 'applicable',
        ]], ['provider' => 'configured-phase-provider', 'model_digest' => 'configured-provider-digest', 'primary_spans' => [$primary]]));
        $this->app->instance('configured-evaluation-provider', $provider);
        config(['qa.evaluation_provider' => 'configured-evaluation-provider']);

        $this->app->call([new EvaluateCall($evaluation->id), 'handle']);

        $step = EvaluationStep::where('status', 'completed')->firstOrFail();
        $this->assertSame('configured-phase-provider', $step->output['metadata']['provider']);
        $this->assertSame('configured-provider-digest', $step->run->model_digest);
        Http::assertNothingSent();
    }

    public function test_a_duplicate_completed_job_cannot_restart_model_work(): void
    {
        $evaluation = $this->evaluation('completed');
        $provider = Mockery::mock(EvaluationProvider::class);
        $provider->shouldNotReceive('modelDigest');
        $this->app->instance(EvaluationProvider::class, $provider);

        $this->app->call([new EvaluateCall($evaluation->id), 'handle']);
        $this->app->call([new EvaluateCall($evaluation->id), 'handle']);

        $this->assertDatabaseCount('evaluation_runs', 0);
        $this->assertDatabaseCount('evaluation_steps', 0);
        $this->assertSame('completed', $evaluation->fresh()->status);
        Http::assertNothingSent();
    }

    private function evaluation(string $status = 'queued'): Evaluation
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $template = QaTemplate::create(['name' => 'Recovery rubric', 'created_by' => $manager->id]);
        $version = $template->versions()->create(['number' => 1, 'name' => $template->name]);
        $version->criteria()->create([
            'title' => 'Greeting', 'question' => 'Did the agent identify the bank?',
            'pass_standard' => 'The agent identifies the bank.', 'weight' => 1, 'display_order' => 0,
        ]);
        $version->update(['published_at' => now()]);
        $template->update(['current_version_id' => $version->id]);
        $call = CallRecording::create([
            'original_filename' => 'recovery.wav', 'path' => 'calls/'.Str::uuid().'.wav',
            'mime_type' => 'audio/wav', 'size' => 1000, 'duration' => 10.0,
            'is_complete' => true, 'uploaded_by' => $manager->id, 'status' => $status,
        ]);
        $call->segments()->create(['sequence' => 1, 'start' => 0.0, 'end' => 5.0, 'text' => 'This is Example Bank. My name is Alex.', 'speaker' => null]);

        return $call->evaluations()->create(['template_version_id' => $version->id, 'status' => $status]);
    }

    private function evaluationRun(Evaluation $evaluation, string $status = 'running'): EvaluationRun
    {
        return EvaluationRun::create([
            'evaluation_id' => $evaluation->id, 'fingerprint' => hash('sha256', (string) Str::uuid()),
            'model_digest' => 'test-model-digest', 'prompt_version' => 'test-phase-v1',
            'criteria_snapshot' => [], 'segments_snapshot' => [], 'context' => [],
            'context_tokens' => 16384, 'plan_revision' => 1, 'status' => $status,
        ]);
    }

    private function step(EvaluationRun $run, string $status, array $attributes = []): EvaluationStep
    {
        return $run->steps()->create($attributes + [
            'step_key' => hash('sha256', (string) Str::uuid()), 'kind' => 'window', 'status' => $status,
            'plan_revision' => 1, 'context_tokens' => 16384, 'criterion_ids' => [], 'payload' => [],
        ]);
    }
}
