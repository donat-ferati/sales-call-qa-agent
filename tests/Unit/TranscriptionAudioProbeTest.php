<?php

namespace Tests\Unit;

use App\AI\AudioProbe;
use App\AI\Exceptions\ProcessingException;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class TranscriptionAudioProbeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/qa-probe-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        file_put_contents($this->directory.'/ffprobe.exe', 'test-binary');
        file_put_contents($this->directory.'/audio.wav', 'test-audio');
        config(['qa.audio.ffprobe' => $this->directory.'/ffprobe.exe']);
        Process::preventStrayProcesses();
    }

    protected function tearDown(): void
    {
        unlink($this->directory.'/ffprobe.exe');
        unlink($this->directory.'/audio.wav');
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_it_normalizes_ffprobe_string_duration_at_the_boundary(): void
    {
        Process::fake(['*' => Process::result(output: json_encode([
            'format' => ['duration' => '12.500', 'format_name' => 'wav'],
            'streams' => [['codec_name' => 'pcm_s16le', 'channels' => 2]],
        ]))]);

        $metadata = app(AudioProbe::class)->inspect($this->directory.'/audio.wav');

        $this->assertSame(12.5, $metadata['duration']);
        $this->assertSame(2, $metadata['channels']);
    }

    public function test_it_rejects_an_audio_stream_exceeding_the_duration_limit(): void
    {
        Process::fake(['*' => Process::result(output: json_encode([
            'format' => ['duration' => '901', 'format_name' => 'wav'],
            'streams' => [['codec_name' => 'pcm_s16le', 'channels' => 1]],
        ]))]);
        $this->expectExceptionMessage('15-minute');

        app(AudioProbe::class)->inspect($this->directory.'/audio.wav');
    }

    public function test_it_rejects_files_without_an_audio_stream(): void
    {
        Process::fake(['*' => Process::result(output: '{"format":{"duration":"4","format_name":"mp4"},"streams":[]}')]);
        $this->expectException(ProcessingException::class);

        app(AudioProbe::class)->inspect($this->directory.'/audio.wav');
    }
}
