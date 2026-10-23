<?php

namespace Tests\Feature;

use App\Actions\PublishTemplate;
use App\Actions\SaveTemplate;
use App\Models\CallRecording;
use App\Models\Evaluation;
use App\Models\User;
use App\Services\ProcessingWorkspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProcessingWorkspacesTest extends TestCase
{
    use RefreshDatabase;

    private Evaluation $evaluation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager);
        $template = app(SaveTemplate::class)->handle($manager, [
            'name' => 'Workspace review', 'description' => null,
            'criteria' => [[
                'title' => 'Greeting', 'question' => 'Did the agent greet the caller?', 'instructions' => null,
                'pass_standard' => 'Greet the caller.', 'applicability' => null, 'weight' => 1, 'required' => true,
            ]],
        ]);
        $version = app(PublishTemplate::class)->handle($template);
        Storage::disk('local')->put('calls/original.wav', 'Original private recording.');
        $call = CallRecording::create([
            'original_filename' => 'original.wav', 'path' => 'calls/original.wav', 'mime_type' => 'audio/wav',
            'size' => 27, 'duration' => 10, 'uploaded_by' => $manager->id, 'status' => 'transcribing', 'is_complete' => true,
        ]);
        $this->evaluation = $call->evaluations()->create([
            'template_version_id' => $version->id, 'status' => 'transcribing',
            'attempt_token' => (string) Str::uuid(), 'started_at' => now(),
        ]);
    }

    public function test_workspace_manifest_attributes_the_current_persisted_attempt(): void
    {
        $service = app(ProcessingWorkspaces::class);
        $directory = $service->create(Storage::disk('local')->path('calls/original.wav'));

        try
        {
            $manifest = json_decode(file_get_contents($directory.'/workspace.json'), true, 8, JSON_THROW_ON_ERROR);
            $this->assertSame($this->evaluation->id, $manifest['owner']['evaluation_id']);
            $this->assertSame($this->evaluation->call_recording_id, $manifest['owner']['call_recording_id']);
            $this->assertSame($this->evaluation->attempt_token, $manifest['owner']['attempt_token']);
            $this->assertStringStartsWith(Storage::disk('local')->path('processing'), $directory);
        }
        finally
        {
            $this->assertTrue($service->remove($directory));
        }
        Storage::disk('local')->assertExists('calls/original.wav');
    }

    public function test_an_activity_lock_protects_a_workspace_even_when_database_ownership_changes(): void
    {
        $service = app(ProcessingWorkspaces::class);
        $directory = $service->create(Storage::disk('local')->path('calls/original.wav'));

        try
        {
            $this->age($directory);
            $this->evaluation->update(['status' => 'failed', 'attempt_token' => null]);
            $otherService = app(ProcessingWorkspaces::class);

            $this->assertNotSame($service, $otherService);
            $this->assertSame(0, $otherService->cleanup());
            $this->assertDirectoryExists($directory);
        }
        finally
        {
            $this->assertTrue($service->remove($directory));
        }
    }

    public function test_cleanup_preserves_matching_active_owners_then_removes_abandoned_copies(): void
    {
        $directory = $this->orphan($this->evaluation);
        $service = app(ProcessingWorkspaces::class);
        $this->assertSame(0, $service->cleanup());
        $this->assertDirectoryExists($directory);
        $this->evaluation->update(['status' => 'failed', 'attempt_token' => null]);

        $this->assertSame(1, $service->cleanup());
        $this->assertDirectoryDoesNotExist($directory);
        Storage::disk('local')->assertExists('calls/original.wav');
    }

    public function test_an_old_attempt_does_not_block_cleanup_after_a_new_attempt_claims_the_call(): void
    {
        $directory = $this->orphan($this->evaluation);
        $this->evaluation->update(['attempt_token' => (string) Str::uuid()]);

        $this->assertSame(1, app(ProcessingWorkspaces::class)->cleanup());
        $this->assertDirectoryDoesNotExist($directory);
        Storage::disk('local')->assertExists('calls/original.wav');
    }

    public function test_legacy_cleanup_waits_until_transcription_is_inactive_and_keeps_recent_or_unrelated_paths(): void
    {
        $old = $this->orphan(null, false);
        $recent = Storage::disk('local')->path('processing/'.Str::uuid());
        mkdir($recent, 0777, true);
        file_put_contents($recent.'/audio.wav', 'Recent workspace.');
        Storage::disk('local')->put('processing/unrelated/keep.txt', 'An unrelated directory.');
        Storage::disk('local')->put('imports/keep.wav', 'An upload in preparation.');
        $service = app(ProcessingWorkspaces::class);

        $this->assertSame(0, $service->cleanup());
        $this->evaluation->update(['status' => 'completed', 'attempt_token' => null]);

        $this->assertSame(1, $service->cleanup());
        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($recent);
        Storage::disk('local')->assertExists(['processing/unrelated/keep.txt', 'imports/keep.wav', 'calls/original.wav']);
    }

    public function test_cleanup_is_bounded_and_subsequent_invocations_continue_removing_old_workspaces(): void
    {
        $this->evaluation->update(['status' => 'completed', 'attempt_token' => null]);
        $this->orphan(null);
        $this->orphan(null);
        config(['qa.processing.cleanup_max_directories' => 1]);
        $service = app(ProcessingWorkspaces::class);

        $this->assertSame(1, $service->cleanup());
        $this->assertSame(1, $service->cleanup());
        $this->assertSame(0, $service->cleanup());
    }

    public function test_remove_refuses_paths_outside_the_processing_root(): void
    {
        Storage::disk('local')->put('imports/'.Str::uuid().'/keep.wav', 'Protected upload.');
        $directory = Storage::disk('local')->directories('imports')[0];

        $this->assertFalse(app(ProcessingWorkspaces::class)->remove(Storage::disk('local')->path($directory)));
        $this->assertCount(1, Storage::disk('local')->allFiles('imports'));
    }

    public function test_cleanup_does_not_follow_a_processing_directory_symlink(): void
    {
        $this->evaluation->update(['status' => 'completed', 'attempt_token' => null]);
        Storage::disk('local')->put('outside/keep.wav', 'Outside workspace root.');
        Storage::disk('local')->makeDirectory('processing');
        $link = Storage::disk('local')->path('processing/'.Str::uuid());

        if (!@symlink(Storage::disk('local')->path('outside'), $link))
        {
            $this->markTestSkipped('This Windows account cannot create directory symlinks.');
        }

        try
        {
            $this->assertSame(0, app(ProcessingWorkspaces::class)->cleanup());
            Storage::disk('local')->assertExists('outside/keep.wav');
        }
        finally
        {
            if (PHP_OS_FAMILY === 'Windows')
            {
                rmdir($link);
            }
            else
            {
                unlink($link);
            }
        }
    }

    private function orphan(?Evaluation $owner, bool $withManifest = true): string
    {
        $directory = Storage::disk('local')->path('processing/'.Str::uuid());
        mkdir($directory, 0777, true);
        file_put_contents($directory.'/audio.wav', 'Abandoned decoded private audio.');
        file_put_contents($directory.'/transcript.json', '{"text":"Private speech"}');

        if ($withManifest)
        {
            file_put_contents($directory.'/workspace.lock', '');
            file_put_contents($directory.'/workspace.json', json_encode([
                'version' => 1, 'created_at' => time() - 86400,
                'owner' => $owner === null ? null : [
                    'evaluation_id' => $owner->id, 'call_recording_id' => $owner->call_recording_id, 'attempt_token' => $owner->attempt_token,
                ],
            ], JSON_THROW_ON_ERROR));
        }
        $this->age($directory);

        return $directory;
    }

    private function age(string $directory): void
    {
        $path = $directory.'/workspace.json';

        if (is_file($path))
        {
            $manifest = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            $manifest['created_at'] = time() - 86400;
            file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
        }
        touch($directory, time() - 86400);
        clearstatcache(true, $directory);
    }
}
