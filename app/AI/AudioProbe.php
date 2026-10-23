<?php

namespace App\AI;

use App\AI\Exceptions\ProcessingException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Process\Factory;
use JsonException;
use Throwable;

final class AudioProbe
{
    public function __construct(
        private Factory $process,
        private Repository $config,
    ) {
    }

    /** @return array{duration: float, channels: int, codec: string, format: string} */
    public function inspect(string $path): array
    {
        $binary = $this->config->get('qa.audio.ffprobe');

        if (!is_file($path) || !is_readable($path))
        {
            throw new ProcessingException('The recording cannot be read. Upload the file again.');
        }

        if (!is_string($binary) || !is_file($binary))
        {
            throw new ProcessingException('FFprobe is not configured. Run the local AI setup script.');
        }

        try
        {
            $result = $this->process->timeout($this->config->get('qa.audio.probe_timeout'))->run([
                $binary, '-v', 'error', '-select_streams', 'a:0', '-show_entries',
                'format=duration,format_name:stream=codec_name,channels,duration', '-of', 'json', $path,
            ]);
        }
        catch (Throwable)
        {
            throw new ProcessingException('Audio inspection timed out or could not start. Check the local FFprobe installation.');
        }

        if ($result->failed())
        {
            throw new ProcessingException('The recording is corrupt or contains no supported audio stream.');
        }

        try
        {
            $data = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        }
        catch (JsonException)
        {
            throw new ProcessingException('FFprobe returned invalid audio metadata.');
        }

        $stream = $data['streams'][0] ?? null;
        $duration = $data['format']['duration'] ?? ($stream['duration'] ?? null);

        if (!is_array($stream) || !is_numeric($duration) || !is_int($stream['channels'] ?? null))
        {
            throw new ProcessingException('The recording has no readable audio duration or channel information.');
        }

        $duration = (float) $duration;

        if (!is_finite($duration) || $duration <= 0 || $stream['channels'] < 1)
        {
            throw new ProcessingException('The recording is empty or its duration is invalid.');
        }

        if ($duration > $this->config->get('qa.upload.max_duration_seconds'))
        {
            throw new ProcessingException('The recording exceeds the 15-minute processing limit.');
        }

        if (!is_string($stream['codec_name'] ?? null) || !is_string($data['format']['format_name'] ?? null))
        {
            throw new ProcessingException('The recording codec could not be identified.');
        }

        return [
            'duration' => $duration,
            'channels' => $stream['channels'],
            'codec' => $stream['codec_name'],
            'format' => $data['format']['format_name'],
        ];
    }
}
