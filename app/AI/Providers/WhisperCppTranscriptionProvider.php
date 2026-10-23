<?php

namespace App\AI\Providers;

use App\AI\AudioProbe;
use App\AI\Contracts\TranscriptionProvider;
use App\AI\Data\TranscriptionResult;
use App\AI\Exceptions\ProcessingException;
use App\AI\TranscriptionNormalizer;
use App\Services\ProcessingWorkspaces;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory;
use JsonException;
use Throwable;

final class WhisperCppTranscriptionProvider implements TranscriptionProvider
{
    public function __construct(
        private AudioProbe $probe,
        private TranscriptionNormalizer $normalizer,
        private Factory $process,
        private Filesystem $files,
        private Repository $config,
        private ProcessingWorkspaces $workspaces,
    ) {
    }

    public function transcribe(string $absoluteAudioPath): TranscriptionResult
    {
        $audio = $this->probe->inspect($absoluteAudioPath);
        $ffmpeg = $this->config->get('qa.audio.ffmpeg');
        $binary = $this->config->get('qa.whisper.binary');
        $model = $this->config->get('qa.whisper.model');

        foreach ([$ffmpeg, $binary, $model] as $asset)
        {
            if (!is_string($asset) || !is_file($asset))
            {
                throw new ProcessingException('Local transcription tools or the small.en model are missing. Run the local AI setup script.');
            }
        }

        $directory = $this->workspaces->create($absoluteAudioPath);
        $prepared = $directory.'/audio.wav';
        $output = $directory.'/transcript';
        $started = microtime(true);

        try
        {
            $decoded = $this->process->timeout($this->config->get('qa.audio.decode_timeout'))->run([
                $ffmpeg, '-nostdin', '-v', 'error', '-i', $absoluteAudioPath, '-map', '0:a:0',
                '-vn', '-ar', '16000', '-ac', '1', '-c:a', 'pcm_s16le', '-y', $prepared,
            ]);

            if ($decoded->failed())
            {
                throw new ProcessingException('The recording could not be decoded. Upload a valid supported audio file.');
            }

            $transcribed = $this->process->timeout($this->config->get('qa.whisper.timeout'))->run([
                $binary, '-m', $model, '-f', $prepared, '-l', $this->config->get('qa.whisper.language'),
                '-t', (string) $this->config->get('qa.whisper.threads'), '-ng', '-oj', '-of', $output,
            ]);

            if ($transcribed->failed() || !is_file($output.'.json'))
            {
                throw new ProcessingException('Local transcription failed. Check the Whisper installation and retry transcription.');
            }

            try
            {
                $payload = json_decode($this->files->get($output.'.json'), true, 512, JSON_THROW_ON_ERROR);
            }
            catch (JsonException)
            {
                throw new ProcessingException('Whisper returned malformed transcription output.');
            }

            if (!is_array($payload))
            {
                throw new ProcessingException('Whisper returned an unexpected transcription response.');
            }

            return $this->normalizer->normalize($payload, $audio['duration'], $this->config->get('qa.whisper.language'), [
                'provider' => 'whisper.cpp',
                'model' => $this->config->get('qa.whisper.model_name'),
                'model_sha256' => hash_file('sha256', $model),
                'elapsed_seconds' => round(microtime(true) - $started, 3),
                'timestamp_precision' => 'segment',
                'speaker_attribution' => 'unavailable',
                'channels_in_source' => $audio['channels'],
                'vad_enabled' => false,
                'device' => 'cpu',
            ]);
        }
        catch (ProcessingException $exception)
        {
            throw $exception;
        }
        catch (Throwable)
        {
            throw new ProcessingException('Local audio processing timed out or could not start. Check the configured tools and retry.');
        }
        finally
        {
            $this->workspaces->remove($directory);
        }
    }
}
