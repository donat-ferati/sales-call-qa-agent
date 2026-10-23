<?php

namespace Tests\Feature;

use App\Actions\ArchiveTemplate;
use App\Actions\DuplicateTemplate;
use App\Actions\MarkReviewed;
use App\Actions\OverrideCriterion;
use App\Actions\PersistEvaluation;
use App\Actions\PublishTemplate;
use App\Actions\RecalculateEvaluation;
use App\Actions\RetryEvaluation;
use App\Actions\SaveTemplate;
use App\AI\Contracts\EvaluationProvider;
use App\AI\Contracts\TranscriptionProvider;
use App\AI\Data\EvaluationResult;
use App\AI\Data\TranscriptionResult;
use App\Jobs\EvaluateCall;
use App\Jobs\TranscribeCall;
use App\Livewire\Templates\Index as TemplateIndex;
use App\Models\CallRecording;
use App\Models\CriterionResult;
use App\Models\Evaluation;
use App\Models\QaTemplate;
use App\Models\TemplateVersion;
use App\Models\TranscriptSegment;
use App\Models\User;
use App\Services\ProcessingAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class DomainWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($this->manager);
    }

    public function test_editing_a_published_template_preserves_the_historical_version(): void
    {
        $template = $this->publishedTemplate();
        $originalVersion = $template->currentVersion;
        $originalCriterion = $originalVersion->criteria()->firstOrFail();
        $evaluation = $this->evaluation($originalVersion);
        $changed = $this->templateData('Revised greeting');
        $changed['criteria'][0]['pass_standard'] = 'State the full organisation name and team.';
        app(SaveTemplate::class)->handle($this->manager, $changed, $template);
        $revised = app(PublishTemplate::class)->handle($template->fresh());

        $this->assertSame(2, $revised->number);
        $this->assertNotSame($originalVersion->id, $revised->id);
        $this->assertSame('State the organisation and your name.', $originalCriterion->fresh()->pass_standard);
        $this->assertSame($originalVersion->id, $evaluation->fresh()->template_version_id);
        $this->assertSame($revised->id, $template->fresh()->current_version_id);
        $this->assertDatabaseCount('template_criteria', 2);
    }

    public function test_archiving_preserves_history_and_duplicate_produces_an_editable_draft(): void
    {
        $template = $this->publishedTemplate();
        $evaluation = $this->evaluation($template->currentVersion);
        app(ArchiveTemplate::class)->handle($template);
        $copy = app(DuplicateTemplate::class)->handle($template->fresh(), $this->manager);

        $this->assertTrue($template->fresh()->is_archived);
        $this->assertSame($template->current_version_id, $evaluation->fresh()->template_version_id);
        $this->assertNotSame($template->id, $copy->id);
        $this->assertFalse($copy->is_archived);
        $this->assertNull($copy->current_version_id);
        $this->assertNull($copy->versions()->firstOrFail()->published_at);
        $this->assertSame('Greeting', $copy->versions()->firstOrFail()->criteria()->firstOrFail()->title);
    }

    public function test_an_archived_template_cannot_be_edited(): void
    {
        $template = $this->publishedTemplate();
        app(ArchiveTemplate::class)->handle($template);
        $this->expectException(ValidationException::class);
        app(SaveTemplate::class)->handle($this->manager, $this->templateData(), $template);
    }

    public function test_unknown_roles_receive_forbidden_for_template_management(): void
    {
        $outsider = User::factory()->create(['role' => 'viewer']);
        Livewire::actingAs($outsider)->test(TemplateIndex::class)->assertForbidden();
    }

    public function test_overrides_preserve_ai_results_append_history_and_recalculate(): void
    {
        $evaluation = $this->evaluation($this->publishedTemplate()->currentVersion, 'completed');
        $result = $this->criterionResult($evaluation, 'FAIL');
        app(RecalculateEvaluation::class)->handle($evaluation, true);
        app(OverrideCriterion::class)->handle($result, $this->manager, 'PASS', 'Audio confirms the complete greeting.', 0);

        $this->assertSame('FAIL', $result->fresh()->ai_decision);
        $this->assertSame('PASS', $result->fresh()->final_decision);
        $this->assertSame(1, $result->fresh()->revision);
        $this->assertSame('0.00', $evaluation->fresh()->ai_score);
        $this->assertSame('100.00', $evaluation->fresh()->final_score);
        $this->assertDatabaseHas('score_overrides', [
            'criterion_result_id' => $result->id, 'previous_decision' => 'FAIL',
            'new_decision' => 'PASS', 'user_id' => $this->manager->id,
        ]);
        $this->assertSame('pending', $evaluation->fresh()->review_status);
    }

    public function test_stale_concurrent_override_cannot_replace_the_newer_decision(): void
    {
        $evaluation = $this->evaluation($this->publishedTemplate()->currentVersion, 'completed');
        $result = $this->criterionResult($evaluation, 'FAIL');
        $action = app(OverrideCriterion::class);
        $action->handle($result, $this->manager, 'PASS', 'Verified the complete greeting in audio.', 0);

        try
        {
            $action->handle($result, $this->manager, 'FAIL', 'This stale form must not replace the review.', 0);
            $this->fail('A stale review revision was accepted.');
        }
        catch (ValidationException $exception)
        {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        $this->assertSame('PASS', $result->fresh()->final_decision);
        $this->assertSame(1, $result->overrides()->count());
    }

    public function test_override_requires_a_meaningful_reason(): void
    {
        $evaluation = $this->evaluation($this->publishedTemplate()->currentVersion, 'completed');
        $result = $this->criterionResult($evaluation, 'FAIL');

        try
        {
            app(OverrideCriterion::class)->handle($result, $this->manager, 'PASS', '   ', 0);
            $this->fail('An empty override reason was accepted.');
        }
        catch (ValidationException $exception)
        {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        $this->assertDatabaseCount('score_overrides', 0);
        $this->assertSame('FAIL', $result->fresh()->final_decision);
    }

    public function test_review_remains_pending_until_insufficient_evidence_is_resolved(): void
    {
        $evaluation = $this->evaluation($this->publishedTemplate()->currentVersion, 'completed');
        $result = $this->criterionResult($evaluation, 'INSUFFICIENT_EVIDENCE');
        app(RecalculateEvaluation::class)->handle($evaluation, true);

        try
        {
            app(MarkReviewed::class)->handle($evaluation, $this->manager);
            $this->fail('An unresolved evaluation was marked reviewed.');
        }
        catch (ValidationException $exception)
        {
            $this->assertArrayHasKey('review', $exception->errors());
        }
        $this->assertNull($evaluation->fresh()->final_score);
        app(OverrideCriterion::class)->handle($result, $this->manager, 'NOT_APPLICABLE', 'The criterion does not apply to this request.', 0);
        app(MarkReviewed::class)->handle($evaluation->fresh(), $this->manager);
        $this->assertSame('reviewed', $evaluation->fresh()->review_status);
        $this->assertSame($this->manager->id, $evaluation->fresh()->reviewed_by);
        $this->assertNull($evaluation->fresh()->final_score);
    }

    public function test_persisted_evaluation_uses_only_same_call_evidence_and_is_atomic(): void
    {
        $version = $this->publishedTemplate()->currentVersion;
        $evaluation = $this->evaluation($version, 'evaluating');
        $token = (string) Str::uuid();
        $evaluation->update(['attempt_token' => $token]);
        $foreignEvaluation = $this->evaluation($version);
        $foreignSegment = $this->segment($foreignEvaluation);

        try
        {
            app(PersistEvaluation::class)->handle($evaluation->id, $token, $this->assessment($version, 'PASS', $foreignSegment->id));
            $this->fail('Cross-call evidence was persisted.');
        }
        catch (RuntimeException $exception)
        {
            $this->assertStringContainsString('Evidence', $exception->getMessage());
        }
        $this->assertSame('evaluating', $evaluation->fresh()->status);
        $this->assertDatabaseCount('criterion_results', 0);
        $this->assertDatabaseCount('criterion_evidence', 0);

        $validSegment = $this->segment($evaluation);
        app(PersistEvaluation::class)->handle($evaluation->id, $token, $this->assessment($version, 'PASS', $validSegment->id));
        $this->assertSame('completed', $evaluation->fresh()->status);
        $this->assertSame('100.00', $evaluation->fresh()->ai_score);
        $this->assertDatabaseHas('criterion_evidence', ['transcript_segment_id' => $validSegment->id, 'role' => 'supporting']);
    }

    public function test_missing_criterion_results_are_rejected_without_partial_scores(): void
    {
        $evaluation = $this->evaluation($this->publishedTemplate()->currentVersion, 'evaluating');
        $token = (string) Str::uuid();
        $evaluation->update(['attempt_token' => $token]);

        try
        {
            app(PersistEvaluation::class)->handle($evaluation->id, $token, new EvaluationResult([], []));
            $this->fail('An incomplete scorecard was accepted.');
        }
        catch (RuntimeException $exception)
        {
            $this->assertStringContainsString('template version', $exception->getMessage());
        }
        $this->assertDatabaseCount('criterion_results', 0);
        $this->assertNull($evaluation->fresh()->ai_score);
    }

    public function test_a_stale_attempt_cannot_overwrite_a_completed_review(): void
    {
        $version = $this->publishedTemplate()->currentVersion;
        $evaluation = $this->evaluation($version, 'completed');
        $result = $this->criterionResult($evaluation, 'FAIL');
        app(OverrideCriterion::class)->handle($result, $this->manager, 'PASS', 'Verified this directly in the recording.', 0);
        app(MarkReviewed::class)->handle($evaluation, $this->manager);
        app(PersistEvaluation::class)->handle($evaluation->id, (string) Str::uuid(), $this->assessment($version, 'FAIL'));

        $this->assertSame('PASS', $result->fresh()->final_decision);
        $this->assertSame('reviewed', $evaluation->fresh()->review_status);
        $this->assertSame(1, $evaluation->results()->count());
        $this->assertSame(1, $evaluation->overrides()->count());
    }

    public function test_completed_duplicate_jobs_do_not_call_any_provider(): void
    {
        $evaluation = $this->evaluation($this->publishedTemplate()->currentVersion, 'completed');
        $this->criterionResult($evaluation, 'PASS');
        $transcriber = Mockery::mock(TranscriptionProvider::class)->shouldNotReceive('transcribe')->getMock();
        $evaluator = Mockery::mock(EvaluationProvider::class)->shouldNotReceive('evaluate')->getMock();
        $this->app->instance(TranscriptionProvider::class, $transcriber);
        $this->app->instance(EvaluationProvider::class, $evaluator);
        $this->app->call([new TranscribeCall($evaluation->id), 'handle']);
        $this->app->call([new EvaluateCall($evaluation->id), 'handle']);

        $this->assertSame(1, $evaluation->results()->count());
        $this->assertSame('completed', $evaluation->fresh()->status);
    }

    public function test_transcription_retry_reuses_persisted_segments(): void
    {
        Queue::fake();
        $evaluation = $this->evaluation($this->publishedTemplate()->currentVersion);
        $this->segment($evaluation);
        $transcriber = Mockery::mock(TranscriptionProvider::class)->shouldNotReceive('transcribe')->getMock();
        $this->app->instance(TranscriptionProvider::class, $transcriber);
        $this->app->call([new TranscribeCall($evaluation->id), 'handle']);

        Queue::assertPushed(EvaluateCall::class, fn ($job) => $job->evaluationId === $evaluation->id);
        $this->assertSame(1, $evaluation->call->segments()->count());
    }

    public function test_successful_transcription_persists_segments_and_queues_evaluation(): void
    {
        Queue::fake();
        Storage::fake('local');
        $evaluation = $this->evaluation($this->publishedTemplate()->currentVersion);
        $transcriber = Mockery::mock(TranscriptionProvider::class);
        $transcriber->shouldReceive('transcribe')->once()->andReturn(new TranscriptionResult('Hello, I am Linda.', [
            ['sequence' => 0, 'start' => 0.0, 'end' => 3.0, 'text' => 'Hello, I am Linda.', 'speaker' => null],
        ], 'en', 30.0, ['provider' => 'fixture']));
        $this->app->instance(TranscriptionProvider::class, $transcriber);
        $this->app->call([new TranscribeCall($evaluation->id), 'handle']);

        $this->assertSame('queued', $evaluation->fresh()->status);
        $this->assertSame('transcript_ready', $evaluation->call->fresh()->status);
        $this->assertDatabaseHas('transcript_segments', ['call_recording_id' => $evaluation->call_recording_id, 'text' => 'Hello, I am Linda.']);
        Queue::assertPushed(EvaluateCall::class, fn ($job) => $job->evaluationId === $evaluation->id);
    }

    public function test_evaluation_job_passes_persisted_evidence_and_call_completeness_to_provider(): void
    {
        $version = $this->publishedTemplate()->currentVersion;
        $evaluation = $this->evaluation($version);
        $evaluation->call->update(['is_complete' => false]);
        $segment = $this->segment($evaluation);
        $evaluator = Mockery::mock(EvaluationProvider::class);
        $evaluator->shouldReceive('evaluate')->once()->withArgs(fn ($criteria, $segments, $context) => $criteria[0]['id'] === $version->criteria()->firstOrFail()->id
            && $segments[0]['id'] === $segment->id
            && $context['is_complete'] === false
        )->andReturn($this->assessment($version, 'INSUFFICIENT_EVIDENCE'));
        $this->app->instance(EvaluationProvider::class, $evaluator);
        $this->app->call([new EvaluateCall($evaluation->id), 'handle']);

        $this->assertSame('completed', $evaluation->fresh()->status);
        $this->assertNull($evaluation->fresh()->ai_score);
        $this->assertSame('0.00', $evaluation->fresh()->ai_coverage);
        $this->assertSame('INSUFFICIENT_EVIDENCE', $evaluation->results()->firstOrFail()->ai_decision);
    }

    public function test_active_attempt_claims_prevent_duplicate_processing_and_stale_failures(): void
    {
        $evaluation = $this->evaluation($this->publishedTemplate()->currentVersion);
        $attempts = app(ProcessingAttempt::class);
        $token = $attempts->claim($evaluation->id, 'evaluating');

        $this->assertNotNull($token);
        $this->assertNull($attempts->claim($evaluation->id, 'evaluating'));
        $attempts->fail($evaluation->id, (string) Str::uuid(), 'Stale failure must be ignored.');
        $this->assertSame('evaluating', $evaluation->fresh()->status);
        $attempts->fail($evaluation->id, $token, 'Actionable retry message.');
        $this->assertSame('failed', $evaluation->fresh()->status);
        $this->assertSame('Actionable retry message.', $evaluation->fresh()->error_message);
    }

    public function test_failed_provider_is_visible_and_keeps_existing_transcript_for_retry(): void
    {
        Queue::fake();
        $evaluation = $this->evaluation($this->publishedTemplate()->currentVersion);
        $segment = $this->segment($evaluation);
        $evaluator = Mockery::mock(EvaluationProvider::class);
        $evaluator->shouldReceive('evaluate')->once()->andThrow(new RuntimeException('Provider-specific failure.'));
        $this->app->instance(EvaluationProvider::class, $evaluator);

        try
        {
            $this->app->call([new EvaluateCall($evaluation->id), 'handle']);
            $this->fail('The failed provider was reported as completed.');
        }
        catch (RuntimeException $exception)
        {
            $this->assertStringContainsString('Evaluation failed', $exception->getMessage());
        }
        $this->assertSame('failed', $evaluation->fresh()->status);
        $this->assertDatabaseHas('transcript_segments', ['id' => $segment->id]);
        $this->assertDatabaseCount('criterion_results', 0);
        app(RetryEvaluation::class)->handle($evaluation->fresh());
        $this->assertSame('queued', $evaluation->fresh()->status);
        $this->assertSame('transcript_ready', $evaluation->call->fresh()->status);
        Queue::assertPushed(EvaluateCall::class, fn ($job) => $job->evaluationId === $evaluation->id);
    }

    private function templateData(string $title = 'Greeting'): array
    {
        return [
            'name' => 'Custom natural-language QA', 'description' => 'A manager-defined rubric.',
            'criteria' => [[
                'title' => $title, 'question' => 'Did the agent introduce themselves?',
                'instructions' => 'Use speaker context rather than a keyword alone.',
                'pass_standard' => 'State the organisation and your name.',
                'applicability' => 'Applies to the start of a complete call.',
                'weight' => 3, 'required' => true,
            ]],
        ];
    }

    private function publishedTemplate(): QaTemplate
    {
        $template = app(SaveTemplate::class)->handle($this->manager, $this->templateData());
        app(PublishTemplate::class)->handle($template);

        return $template->fresh('currentVersion.criteria');
    }

    private function evaluation(TemplateVersion $version, string $status = 'queued'): Evaluation
    {
        $call = CallRecording::create([
            'original_filename' => 'sample.wav', 'path' => 'calls/'.Str::uuid().'.wav',
            'mime_type' => 'audio/wav', 'size' => 1000, 'duration' => 30.0,
            'uploaded_by' => $this->manager->id, 'status' => $status,
        ]);

        return $call->evaluations()->create(['template_version_id' => $version->id, 'status' => $status]);
    }

    private function criterionResult(Evaluation $evaluation, string $decision): CriterionResult
    {
        return $evaluation->results()->create([
            'template_criterion_id' => $evaluation->templateVersion->criteria()->firstOrFail()->id,
            'ai_decision' => $decision, 'final_decision' => $decision,
            'ai_explanation' => 'Original AI explanation.',
            'evidence_basis' => $decision === 'INSUFFICIENT_EVIDENCE' ? 'uncertain' : 'missing',
        ]);
    }

    private function segment(Evaluation $evaluation): TranscriptSegment
    {
        return $evaluation->call->segments()->create([
            'sequence' => 0, 'start' => 0.0, 'end' => 5.0,
            'text' => 'Hello, this is the bank. My name is Linda.', 'speaker' => null,
        ]);
    }

    private function assessment(TemplateVersion $version, string $decision, ?int $segmentId = null): EvaluationResult
    {
        return new EvaluationResult([[
            'criterion_id' => $version->criteria()->firstOrFail()->id,
            'decision' => $decision, 'explanation' => 'The supplied evidence supports this decision.',
            'uncertainty' => null, 'evidence_basis' => $segmentId ? 'positive' : 'uncertain',
            'evidence' => $segmentId ? [['segment_id' => $segmentId, 'role' => 'supporting']] : [],
        ]], ['provider' => 'fixture', 'model' => 'isolated-test']);
    }
}
