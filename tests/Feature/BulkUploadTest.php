<?php

namespace Tests\Feature;

use App\Actions\PublishTemplate;
use App\Actions\SaveTemplate;
use App\Actions\StartCallUploadBatch;
use App\Actions\UploadCall;
use App\Models\CallRecording;
use App\Models\Evaluation;
use App\Models\QaTemplate;
use App\Models\User;
use App\Services\Uploads\PrepareUploadBatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

final class BulkUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private QaTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($this->manager);
        $this->template = app(SaveTemplate::class)->handle($this->manager, [
            'name' => 'Upload review', 'description' => null,
            'criteria' => [[
                'title' => 'Greeting', 'question' => 'Did the agent greet the caller?', 'instructions' => null,
                'pass_standard' => 'Greet the caller.', 'applicability' => null, 'weight' => 1, 'required' => true,
            ]],
        ]);
        app(PublishTemplate::class)->handle($this->template);
        $this->template->refresh();
        config(['qa.audio.ffprobe' => PHP_BINARY]);
        $this->probeDuration(10);
    }

    public function test_mixed_audio_and_zip_batch_imports_recordings_and_reports_skipped_entries(): void
    {
        $batch = app(StartCallUploadBatch::class)->handle($this->manager, [
            UploadedFile::fake()->createWithContent('first.wav', $this->audio()),
            $this->archive(['calls/second.wav' => $this->audio(), 'notes.txt' => 'Source attribution.']),
        ], $this->template->id, false);
        app(PrepareUploadBatch::class)->handle($batch->id);

        $this->assertSame('prepared', $batch->fresh()->status);
        $this->assertDatabaseCount('call_recordings', 2);
        $this->assertDatabaseCount('evaluations', 2);
        $this->assertSame(1, $batch->items()->where('status', 'skipped')->where('entry_path', 'notes.txt')->count());
        $this->assertSame(2, Evaluation::where('template_version_id', $batch->template_version_id)->count());
        $this->assertSame(0, CallRecording::where('is_complete', true)->count());
        $this->assertSame(0, $batch->items()->whereNotNull('stored_path')->count());
        Storage::disk('local')->assertDirectoryEmpty('bulk-uploads');
        $this->assertCount(2, Storage::disk('local')->files('calls'));
    }

    public function test_a_rejected_recording_does_not_prevent_other_archive_entries_from_importing(): void
    {
        $batch = app(StartCallUploadBatch::class)->handle($this->manager, [
            $this->archive(['valid.wav' => $this->audio(), 'invalid.wav' => 'This is text rather than audio.']),
        ], $this->template->id, true);
        app(PrepareUploadBatch::class)->handle($batch->id);

        $this->assertSame('prepared', $batch->fresh()->status);
        $this->assertDatabaseCount('call_recordings', 1);
        $this->assertSame(1, $batch->items()->where('original_name', 'invalid.wav')->where('status', 'rejected')->count());
        $this->assertSame(1, $batch->items()->where('original_name', 'valid.wav')->whereNotNull('evaluation_id')->count());
    }

    public function test_interrupted_import_reuses_the_existing_recording_identity(): void
    {
        $batch = app(StartCallUploadBatch::class)->handle($this->manager, [UploadedFile::fake()->createWithContent('call.wav', $this->audio())], $this->template->id, true);
        $item = $batch->sources()->firstOrFail();
        $file = new UploadedFile(Storage::disk('local')->path($item->stored_path), $item->original_name, null, UPLOAD_ERR_OK, true);
        $existing = app(UploadCall::class)->handle($this->manager, $file, $batch->qa_template_id, true, $batch->template_version_id, $item->idempotency_key);
        $batch->update(['status' => 'failed']);
        $item->update(['status' => 'validating']);

        app(PrepareUploadBatch::class)->handle($batch->id);

        $this->assertSame('prepared', $batch->fresh()->status);
        $this->assertSame($existing->id, $item->fresh()->evaluation_id);
        $this->assertDatabaseCount('call_recordings', 1);
        $this->assertDatabaseCount('evaluations', 1);
        $this->assertCount(1, Storage::disk('local')->files('calls'));
    }

    #[DataProvider('unsafeEntries')]
    public function test_unsafe_archives_are_rejected_before_any_recording_is_imported(array $entries, string $reason): void
    {
        $batch = app(StartCallUploadBatch::class)->handle($this->manager, [$this->archive($entries)], $this->template->id, true);
        app(PrepareUploadBatch::class)->handle($batch->id);
        $source = $batch->sources()->firstOrFail();

        $this->assertSame('rejected', $source->status);
        $this->assertStringContainsString($reason, $source->error_message);
        $this->assertDatabaseCount('call_recordings', 0);
        Storage::disk('local')->assertDirectoryEmpty('bulk-uploads');
    }

    public static function unsafeEntries(): array
    {
        return [
            'parent traversal' => [['../outside.wav' => 'audio'], 'parent-directory'],
            'backslash traversal' => [['folder\\..\\outside.wav' => 'audio'], 'parent-directory'],
            'absolute path' => [['/outside.wav' => 'audio'], 'unsafe entry path'],
            'drive path' => [['C:\\outside.wav' => 'audio'], 'unsafe entry path'],
            'nested archive' => [['nested.zip' => 'archive'], 'nested archive'],
            'case duplicate' => [['call.wav' => 'one', 'CALL.wav' => 'two'], 'duplicate entry paths'],
            'normalized duplicate' => [['call.wav' => 'one', './call.wav' => 'two'], 'duplicate entry paths'],
        ];
    }

    public function test_symbolic_link_entries_are_rejected(): void
    {
        $file = $this->archive(['link.wav' => 'outside.wav'], function (ZipArchive $archive)
        {
            $this->assertTrue($archive->setExternalAttributesName('link.wav', ZipArchive::OPSYS_UNIX, 0120777 << 16));
        });
        $batch = app(StartCallUploadBatch::class)->handle($this->manager, [$file], $this->template->id, true);
        app(PrepareUploadBatch::class)->handle($batch->id);

        $source = $batch->sources()->firstOrFail();
        $this->assertSame('rejected', $source->status);
        $this->assertStringContainsString('links or special files', $source->error_message);
        $this->assertDatabaseCount('call_recordings', 0);
    }

    public function test_corrupt_archives_report_a_rejection(): void
    {
        $batch = app(StartCallUploadBatch::class)->handle($this->manager, [UploadedFile::fake()->createWithContent('broken.zip', 'Not a ZIP directory.')], $this->template->id, true);
        app(PrepareUploadBatch::class)->handle($batch->id);

        $this->assertSame('rejected', $batch->sources()->firstOrFail()->status);
        $this->assertDatabaseCount('call_recordings', 0);
    }

    public function test_encrypted_entries_are_rejected(): void
    {
        if (!ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true))
        {
            $this->markTestSkipped('The installed ZIP library cannot create encrypted fixtures.');
        }
        $file = $this->archive(['call.wav' => $this->audio()], function (ZipArchive $archive)
        {
            $this->assertTrue($archive->setEncryptionName('call.wav', ZipArchive::EM_AES_256, 'fixture-only-password'));
        });
        $batch = app(StartCallUploadBatch::class)->handle($this->manager, [$file], $this->template->id, true);
        app(PrepareUploadBatch::class)->handle($batch->id);

        $source = $batch->sources()->firstOrFail();
        $this->assertSame('rejected', $source->status);
        $this->assertStringContainsString('Encrypted ZIP entries', $source->error_message);
        $this->assertDatabaseCount('call_recordings', 0);
    }

    #[DataProvider('archiveLimits')]
    public function test_archive_expansion_limits_reject_the_whole_archive(string $setting, int $limit): void
    {
        config([$setting => $limit]);
        $batch = app(StartCallUploadBatch::class)->handle($this->manager, [$this->archive(['one.wav' => $this->audio(), 'two.wav' => $this->audio()])], $this->template->id, true);
        app(PrepareUploadBatch::class)->handle($batch->id);

        $this->assertSame('rejected', $batch->sources()->firstOrFail()->status);
        $this->assertDatabaseCount('call_recordings', 0);
        Storage::disk('local')->assertDirectoryEmpty('bulk-uploads');
    }

    public static function archiveLimits(): array
    {
        return [
            'recordings' => ['qa.bulk.max_recordings', 1],
            'directory entries' => ['qa.bulk.max_archive_entries', 1],
            'expanded bytes' => ['qa.bulk.max_expanded_bytes', 100],
        ];
    }

    public function test_combined_source_limit_is_checked_before_staging(): void
    {
        config(['qa.bulk.max_total_bytes' => 100]);

        try
        {
            app(StartCallUploadBatch::class)->handle($this->manager, [UploadedFile::fake()->createWithContent('call.wav', $this->audio())], $this->template->id, true);
            $this->fail('An oversized batch was accepted.');
        }
        catch (ValidationException $exception)
        {
            $this->assertArrayHasKey('sources', $exception->errors());
        }
        $this->assertDatabaseCount('call_upload_batches', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_source_count_and_per_source_limits_are_checked_before_staging(): void
    {
        config(['qa.bulk.max_sources' => 1]);

        try
        {
            app(StartCallUploadBatch::class)->handle($this->manager, [
                UploadedFile::fake()->createWithContent('one.wav', $this->audio()),
                UploadedFile::fake()->createWithContent('two.wav', $this->audio()),
            ], $this->template->id, true);
            $this->fail('Too many selected sources were accepted.');
        }
        catch (ValidationException $exception)
        {
            $this->assertArrayHasKey('sources', $exception->errors());
        }
        config(['qa.bulk.max_sources' => 20, 'qa.bulk.max_source_kilobytes' => 1]);

        try
        {
            app(StartCallUploadBatch::class)->handle($this->manager, [UploadedFile::fake()->createWithContent('large.wav', $this->audio())], $this->template->id, true);
            $this->fail('An oversized source was accepted.');
        }
        catch (ValidationException $exception)
        {
            $this->assertArrayHasKey('sources.0', $exception->errors());
        }
        $this->assertDatabaseCount('call_upload_batches', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_oversized_and_overlong_recordings_are_rejected_individually(): void
    {
        config(['qa.upload.max_kilobytes' => 1]);
        $batch = app(StartCallUploadBatch::class)->handle($this->manager, [UploadedFile::fake()->createWithContent('large.wav', $this->audio())], $this->template->id, true);
        app(PrepareUploadBatch::class)->handle($batch->id);
        $this->assertSame('rejected', $batch->sources()->firstOrFail()->status);
        config(['qa.upload.max_kilobytes' => 25600]);
        $this->probeDuration(901);
        $longBatch = app(StartCallUploadBatch::class)->handle($this->manager, [UploadedFile::fake()->createWithContent('long.wav', $this->audio())], $this->template->id, true);
        app(PrepareUploadBatch::class)->handle($longBatch->id);

        $this->assertSame('rejected', $longBatch->sources()->firstOrFail()->status);
        $this->assertDatabaseCount('call_recordings', 0);
    }

    public function test_an_unauthorized_role_cannot_stage_a_batch(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->expectException(AuthorizationException::class);
        app(StartCallUploadBatch::class)->handle($viewer, [UploadedFile::fake()->createWithContent('call.wav', $this->audio())], $this->template->id, true);
    }

    private function archive(array $entries, ?callable $configure = null): UploadedFile
    {
        Storage::disk('local')->makeDirectory('archive-fixtures');
        $path = Storage::disk('local')->path('archive-fixtures/'.Str::uuid().'.zip');
        $archive = new ZipArchive();
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        foreach ($entries as $name => $content)
        {
            $this->assertTrue($archive->addFromString($name, $content));
        }

        if ($configure !== null)
        {
            $configure($archive);
        }
        $this->assertTrue($archive->close());
        $file = UploadedFile::fake()->createWithContent('calls.zip', file_get_contents($path));
        unlink($path);

        return $file;
    }

    private function audio(): string
    {
        $pcm = str_repeat("\0", 3200);

        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
    }

    private function probeDuration(int $seconds): void
    {
        Process::fake(['*' => Process::result(output: json_encode([
            'streams' => [['codec_name' => 'pcm_s16le', 'channels' => 1]],
            'format' => ['duration' => (string) $seconds, 'format_name' => 'wav'],
        ], JSON_THROW_ON_ERROR))]);
    }
}
