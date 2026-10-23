<?php

namespace Tests\Feature;

use App\Actions\PublishTemplate;
use App\Actions\SaveTemplate;
use App\Livewire\Calls\Create as UploadCallPage;
use App\Livewire\Evaluations\Show as EvaluationPage;
use App\Livewire\Templates\Create as CreateTemplatePage;
use App\Livewire\Templates\Edit as EditTemplatePage;
use App\Livewire\Templates\Index as TemplateIndex;
use App\Livewire\Templates\Show as TemplatePage;
use App\Models\CallRecording;
use App\Models\CriterionEvidence;
use App\Models\CriterionResult;
use App\Models\Evaluation;
use App\Models\QaTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class UiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_access_requires_authentication(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('calls.create'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('Welcome to your workspace');
    }

    public function test_manager_pages_render_and_unknown_role_is_forbidden(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager);
        $template = $this->publishedTemplate($manager);
        $evaluation = $this->evaluation($manager, $template);

        foreach (['dashboard', 'templates.index', 'templates.create', 'calls.index', 'calls.create'] as $route)
        {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('templates.show', $template))->assertOk()->assertSee('Greeting standard');
        $this->get(route('templates.edit', $template))->assertOk();
        $this->get(route('evaluations.show', $evaluation))->assertOk()->assertSee('QA scorecard');

        $outsider = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($outsider)->get(route('dashboard'))->assertForbidden();
        $this->get(route('evaluations.show', $evaluation))->assertForbidden();
    }

    public function test_template_form_publishes_a_version_and_edits_preserve_history(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager);
        $data = $this->templateData();
        Livewire::test(CreateTemplatePage::class)
            ->set('form.name', $data['name'])
            ->set('form.description', $data['description'])
            ->set('form.criteria', $data['criteria'])
            ->call('save')->assertHasNoErrors();
        $template = QaTemplate::firstOrFail();
        Livewire::test(TemplatePage::class, ['template' => $template])->call('publish')->assertHasNoErrors();
        $originalVersion = $template->refresh()->current_version_id;
        $evaluation = $this->evaluation($manager, $template);

        Livewire::test(EditTemplatePage::class, ['template' => $template])
            ->set('form.criteria.0.title', 'Revised greeting')
            ->call('save')->assertHasNoErrors();
        $this->assertSame($originalVersion, $evaluation->fresh()->template_version_id);
        $this->assertSame('Greeting standard', $evaluation->templateVersion->criteria->first()->title);
        Livewire::test(TemplatePage::class, ['template' => $template])->call('publish')->assertHasNoErrors();
        $this->assertNotSame($originalVersion, $template->refresh()->current_version_id);

        Livewire::test(TemplateIndex::class)->call('archive', $template->id)->assertHasNoErrors();
        $this->assertTrue($template->refresh()->is_archived);
        $this->get(route('evaluations.show', $evaluation))->assertOk()->assertSee('Greeting standard');
    }

    public function test_upload_validates_content_and_queues_a_real_file_without_running_ai(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['qa.audio.ffprobe' => __FILE__]);
        Process::fake(['*' => Process::result(errorOutput: 'Invalid audio stream.', exitCode: 1)]);
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager);
        $template = $this->publishedTemplate($manager);
        $this->get(route('calls.create'))->assertOk()->assertSee('wire:submit="uploadRecording"', false)->assertDontSee('wire:submit="upload"', false);

        Livewire::test(UploadCallPage::class)->set('templateId', $template->id)
            ->set('audio', UploadedFile::fake()->createWithContent('not-audio.wav', 'This is not audio.'))
            ->call('uploadRecording')->assertHasErrors(['audio']);
        $this->assertDatabaseCount('call_recordings', 0);

        Process::fake([
            '*' => Process::result(output: json_encode(['streams' => [['codec_name' => 'pcm_s16le', 'channels' => 1]], 'format' => ['duration' => '10.0', 'format_name' => 'wav']])),
        ]);
        $audio = UploadedFile::fake()->createWithContent('recording.wav', $this->waveFile());
        Livewire::test(UploadCallPage::class)->set('templateId', $template->id)->set('audio', $audio)
            ->call('uploadRecording')->assertHasNoErrors();
        $call = CallRecording::firstOrFail();
        Storage::disk('local')->assertExists($call->path);
        $this->assertSame('queued', $call->evaluations->first()->status);
    }

    public function test_audio_stream_supports_range_and_rejects_unauthorized_access(): void
    {
        Storage::fake('local');
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager);
        $evaluation = $this->evaluation($manager, $this->publishedTemplate($manager));
        Storage::disk('local')->put($evaluation->call->path, $this->waveFile());

        $this->get(route('calls.audio', $evaluation->call), ['Range' => 'bytes=0-3'])
            ->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-3/204');
        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->get(route('calls.audio', $evaluation->call))->assertForbidden();
        auth()->logout();
        $this->get(route('calls.audio', $evaluation->call))->assertRedirect(route('login'));
    }

    public function test_review_requires_a_reason_preserves_ai_and_updates_score(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager);
        $evaluation = $this->evaluation($manager, $this->publishedTemplate($manager));
        $result = $evaluation->results->first();
        $page = Livewire::test(EvaluationPage::class, ['evaluation' => $evaluation]);
        $page->call('startOverride', $result->id)->set('newDecision', 'FAIL')->set('reason', '')
            ->call('saveOverride')->assertHasErrors(['reason']);
        $page->set('reason', 'The introduction was spoken by the caller, not the agent.')
            ->call('saveOverride')->assertHasNoErrors();
        $this->assertSame('PASS', $result->refresh()->ai_decision);
        $this->assertSame('FAIL', $result->final_decision);
        $this->assertSame('0.00', $evaluation->refresh()->final_score);
        $this->assertDatabaseCount('score_overrides', 1);
        $page->call('markReviewed')->assertHasNoErrors();
        $this->assertSame('reviewed', $evaluation->refresh()->review_status);
    }

    private function publishedTemplate(User $user): QaTemplate
    {
        $template = app(SaveTemplate::class)->handle($user, $this->templateData());
        app(PublishTemplate::class)->handle($template);

        return $template->refresh();
    }

    private function templateData(): array
    {
        return [
            'name' => 'Conversation fundamentals', 'description' => 'Illustrative test rubric.',
            'criteria' => [[
                'title' => 'Greeting standard', 'question' => 'Did the agent introduce the organisation?',
                'instructions' => 'Check who spoke the introduction.', 'pass_standard' => 'The agent identifies the organisation.',
                'applicability' => '', 'weight' => 1, 'required' => true,
            ]],
        ];
    }

    private function evaluation(User $user, QaTemplate $template): Evaluation
    {
        $call = CallRecording::create([
            'original_filename' => 'sample.wav', 'path' => 'calls/sample.wav', 'mime_type' => 'audio/wav',
            'size' => 204, 'duration' => 10, 'status' => 'transcript_ready', 'uploaded_by' => $user->id, 'is_complete' => true,
        ]);
        $evaluation = $call->evaluations()->create([
            'template_version_id' => $template->current_version_id, 'status' => 'completed',
            'ai_score' => 100, 'final_score' => 100, 'ai_coverage' => 100, 'final_coverage' => 100,
        ]);
        $result = CriterionResult::create([
            'evaluation_id' => $evaluation->id, 'template_criterion_id' => $template->currentVersion->criteria->first()->id,
            'ai_decision' => 'PASS', 'final_decision' => 'PASS', 'ai_explanation' => 'The agent identified the organisation.', 'evidence_basis' => 'positive',
        ]);

        $segment = $call->segments()->create(['sequence' => 0, 'start' => 1, 'end' => 3, 'text' => 'Hello, this is Harper Valley Bank.', 'speaker' => 'Agent']);
        CriterionEvidence::create(['criterion_result_id' => $result->id, 'transcript_segment_id' => $segment->id, 'role' => 'supporting']);

        return $evaluation;
    }

    private function waveFile(): string
    {
        return 'RIFF'.pack('V', 196).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', 160).str_repeat("\0", 160);
    }
}
