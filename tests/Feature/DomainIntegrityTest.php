<?php

namespace Tests\Feature;

use App\Actions\OverrideCriterion;
use App\Actions\PublishTemplate;
use App\Actions\SaveTemplate;
use App\Models\CallRecording;
use App\Models\Evaluation;
use App\Models\QaTemplate;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DomainIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($this->manager);
        Storage::fake('local');
    }

    public function test_new_criteria_cannot_be_added_to_a_published_version(): void
    {
        $template = $this->publishedTemplate();

        try
        {
            $template->currentVersion->criteria()->create($this->criterionData('Unexpected addition'));
            $this->fail('A published version accepted a new criterion.');
        }
        catch (LogicException $exception)
        {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        $this->assertSame(1, $template->currentVersion->criteria()->count());
    }

    public function test_published_criterion_cannot_be_reparented_to_bypass_immutability(): void
    {
        $template = $this->publishedTemplate();
        $criterion = $template->currentVersion->criteria()->firstOrFail();
        $originalVersionId = $criterion->template_version_id;
        $draft = $template->versions()->create(['number' => 2, 'name' => 'Editable draft']);

        try
        {
            $criterion->update(['template_version_id' => $draft->id, 'pass_standard' => 'A different standard.']);
            $this->fail('A published criterion was moved to a draft.');
        }
        catch (LogicException $exception)
        {
            $this->assertStringContainsString('moved between versions', $exception->getMessage());
        }
        $this->assertSame($originalVersionId, $criterion->fresh()->template_version_id);
        $this->assertSame('State the organisation and your name.', $criterion->fresh()->pass_standard);
        $this->assertSame(0, $draft->criteria()->count());
    }

    public function test_cached_draft_relation_does_not_allow_edits_after_publication(): void
    {
        $template = app(SaveTemplate::class)->handle($this->manager, $this->templateData());
        $draft = $template->versions()->firstOrFail();
        $criterion = $draft->criteria()->firstOrFail()->load('version');
        $this->assertNull($criterion->version->published_at);
        app(PublishTemplate::class)->handle($template);

        try
        {
            $criterion->update(['title' => 'Changed after publication']);
            $this->fail('A cached draft relation bypassed publication protection.');
        }
        catch (LogicException $exception)
        {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        $this->assertSame('Greeting', $criterion->fresh()->title);
    }

    public function test_published_criteria_cannot_be_deleted_through_model_operations(): void
    {
        $criterion = $this->publishedTemplate()->currentVersion->criteria()->firstOrFail();

        try
        {
            $criterion->delete();
            $this->fail('A published criterion was deleted.');
        }
        catch (LogicException $exception)
        {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        $this->assertDatabaseHas('template_criteria', ['id' => $criterion->id]);
    }

    public function test_score_override_audit_records_cannot_be_rewritten(): void
    {
        $evaluation = $this->recordingWithHistory();
        $override = $evaluation->overrides()->firstOrFail();
        $reason = $override->reason;

        try
        {
            $override->update(['reason' => 'A replacement explanation.']);
            $this->fail('An audit record was rewritten.');
        }
        catch (LogicException $exception)
        {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
        $this->assertSame($reason, $override->fresh()->reason);
    }

    public function test_score_override_audit_records_cannot_be_deleted_individually(): void
    {
        $evaluation = $this->recordingWithHistory();
        $override = $evaluation->overrides()->firstOrFail();

        try
        {
            $override->delete();
            $this->fail('An audit record was deleted.');
        }
        catch (LogicException $exception)
        {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
        $this->assertDatabaseHas('score_overrides', ['id' => $override->id]);
    }

    public function test_retention_without_an_opt_in_preserves_audio_and_history(): void
    {
        $this->assertTrue(config('qa.retention_days') === null || config('qa.retention_days') === 0);
        $evaluation = $this->recordingWithHistory();
        $this->artisan('qa:prune', ['--execute' => true])
            ->expectsOutputToContain('Retention is disabled')
            ->assertExitCode(Command::SUCCESS);

        Storage::disk('local')->assertExists($evaluation->call->path);
        $this->assertDatabaseHas('call_recordings', ['id' => $evaluation->call_recording_id]);
        $this->assertSame(1, $evaluation->overrides()->count());
    }

    public function test_retention_preview_requires_execute_before_deleting(): void
    {
        config(['qa.retention_days' => 30]);
        $evaluation = $this->recordingWithHistory();
        $this->artisan('qa:prune')->expectsOutputToContain('1 recordings match')->assertExitCode(Command::SUCCESS);

        Storage::disk('local')->assertExists($evaluation->call->path);
        $this->assertDatabaseHas('evaluations', ['id' => $evaluation->id]);
        $this->assertDatabaseCount('score_overrides', 1);
    }

    #[DataProvider('activeStatuses')]
    public function test_retention_skips_active_processing_even_when_recording_is_old(string $status): void
    {
        config(['qa.retention_days' => 30]);
        $evaluation = $this->recordingWithHistory($status);
        $this->artisan('qa:prune', ['--execute' => true])->assertExitCode(Command::SUCCESS);

        Storage::disk('local')->assertExists($evaluation->call->path);
        $this->assertDatabaseHas('call_recordings', ['id' => $evaluation->call_recording_id, 'status' => $status]);
        $this->assertDatabaseHas('evaluations', ['id' => $evaluation->id, 'status' => $status]);
    }

    public static function activeStatuses(): array
    {
        return [['queued'], ['transcribing'], ['transcript_ready'], ['evaluating']];
    }

    public function test_opted_in_retention_deletes_expired_audio_and_dependent_history_only(): void
    {
        config(['qa.retention_days' => 30]);
        $expired = $this->recordingWithHistory();
        $expiredPath = $expired->call->path;
        $expiredResult = $expired->results()->firstOrFail();
        $expiredSegment = $expired->call->segments()->firstOrFail();
        $expiredOverride = $expired->overrides()->firstOrFail();
        $recent = $this->recordingWithHistory(expired: false);
        $this->artisan('qa:prune', ['--execute' => true])->assertExitCode(Command::SUCCESS);

        Storage::disk('local')->assertMissing($expiredPath);
        Storage::disk('local')->assertExists($recent->call->path);
        $this->assertDatabaseMissing('call_recordings', ['id' => $expired->call_recording_id]);
        $this->assertDatabaseMissing('evaluations', ['id' => $expired->id]);
        $this->assertDatabaseMissing('criterion_results', ['id' => $expiredResult->id]);
        $this->assertDatabaseMissing('transcript_segments', ['id' => $expiredSegment->id]);
        $this->assertDatabaseMissing('criterion_evidence', ['criterion_result_id' => $expiredResult->id]);
        $this->assertDatabaseMissing('score_overrides', ['id' => $expiredOverride->id]);
        $this->assertDatabaseHas('evaluations', ['id' => $recent->id]);
        $this->assertDatabaseHas('template_versions', ['id' => $expired->template_version_id]);
    }

    public function test_audio_deletion_failure_preserves_the_recording_and_review_history(): void
    {
        config(['qa.retention_days' => 30]);
        $evaluation = $this->recordingWithHistory();
        $localFiles = Storage::disk('local');
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->once()->with($evaluation->call->path)->andReturn(false);
        $disk->shouldReceive('path')->with('processing')->andReturn($localFiles->path('processing'));
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->artisan('qa:prune', ['--execute' => true])
            ->expectsOutputToContain('was not pruned')
            ->assertExitCode(Command::FAILURE);

        $localFiles->assertExists($evaluation->call->path);
        $this->assertDatabaseHas('call_recordings', ['id' => $evaluation->call_recording_id]);
        $this->assertDatabaseHas('evaluations', ['id' => $evaluation->id]);
        $this->assertDatabaseCount('criterion_results', 1);
        $this->assertDatabaseCount('criterion_evidence', 1);
        $this->assertDatabaseCount('score_overrides', 1);
    }

    private function publishedTemplate(): QaTemplate
    {
        $template = app(SaveTemplate::class)->handle($this->manager, $this->templateData());
        app(PublishTemplate::class)->handle($template);

        return $template->fresh('currentVersion');
    }

    private function templateData(): array
    {
        return ['name' => 'Immutable review rubric', 'description' => null, 'criteria' => [$this->criterionData()]];
    }

    private function criterionData(string $title = 'Greeting'): array
    {
        return [
            'title' => $title, 'question' => 'Did the agent introduce themselves?',
            'pass_standard' => 'State the organisation and your name.',
            'instructions' => null, 'applicability' => null,
            'weight' => 1, 'required' => true, 'display_order' => 0,
        ];
    }

    private function recordingWithHistory(string $status = 'completed', bool $expired = true): Evaluation
    {
        $template = $this->publishedTemplate();
        $path = 'calls/'.Str::uuid().'.wav';
        Storage::disk('local')->put($path, 'Private recording fixture.');
        $call = CallRecording::create([
            'original_filename' => 'private-call.wav', 'path' => $path, 'mime_type' => 'audio/wav',
            'size' => 26, 'duration' => 30, 'status' => $status, 'uploaded_by' => $this->manager->id,
            'created_at' => $expired ? now()->subDays(31) : now(),
        ]);
        $evaluation = $call->evaluations()->create(['template_version_id' => $template->current_version_id, 'status' => $status]);

        if ($status === 'completed')
        {
            $segment = $call->segments()->create(['sequence' => 1, 'start' => 0.0, 'end' => 5.0, 'text' => 'Hello, my name is Linda.', 'speaker' => null]);
            $result = $evaluation->results()->create([
                'template_criterion_id' => $template->currentVersion->criteria()->firstOrFail()->id,
                'ai_decision' => 'FAIL', 'final_decision' => 'FAIL',
                'ai_explanation' => 'The organisation was not identified.', 'evidence_basis' => 'missing',
            ]);
            $result->evidence()->create(['transcript_segment_id' => $segment->id, 'role' => 'contradicting']);
            app(OverrideCriterion::class)->handle($result, $this->manager, 'PASS', 'Listening to the source confirms the complete introduction.', 0);
        }

        return $evaluation->fresh('call');
    }
}
