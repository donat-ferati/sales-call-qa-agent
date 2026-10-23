<?php

use App\AI\Providers\OllamaEvaluationProvider;
use App\AI\Providers\WhisperCppTranscriptionProvider;

return [
    'transcription_provider' => WhisperCppTranscriptionProvider::class,
    'evaluation_provider' => OllamaEvaluationProvider::class,
    'bulk' => [
        'max_sources' => 20,
        'max_source_kilobytes' => 102400,
        'max_total_bytes' => 262144000,
        'max_recordings' => 50,
        'max_archive_entries' => 200,
        'max_expanded_bytes' => 524288000,
        'prepare_timeout' => 1800,
    ],
    'upload' => [
        'max_kilobytes' => 25600,
        'max_duration_seconds' => 900,
        'extensions' => ['mp3', 'wav', 'm4a', 'ogg'],
    ],
    'audio' => [
        'ffmpeg' => env('FFMPEG_PATH', base_path('tools/ffmpeg/ffmpeg-9.0.2-essentials_build/bin/ffmpeg.exe')),
        'ffprobe' => env('FFPROBE_PATH', base_path('tools/ffmpeg/ffmpeg-9.0.2-essentials_build/bin/ffprobe.exe')),
        'probe_timeout' => 30,
        'decode_timeout' => 180,
    ],
    'whisper' => [
        'binary' => env('WHISPER_BINARY', base_path('tools/whisper/Release/whisper-cli.exe')),
        'model' => env('WHISPER_MODEL_PATH', base_path('tools/models/ggml-small.en.bin')),
        'model_name' => 'small.en',
        'language' => 'en',
        'threads' => (int) env('WHISPER_THREADS', 8),
        'timeout' => (int) env('WHISPER_TIMEOUT', 1200),
    ],
    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen3:14b-q4_K_M'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 600),
        'connect_timeout' => 5,
        'context_tokens' => 16384,
        'output_tokens' => 4096,
        'max_prompt_bytes' => 11000,
        'temperature' => 0.7,
        'top_p' => 0.8,
        'top_k' => 20,
        'min_p' => 0,
    ],
    'retention_days' => env('QA_RETENTION_DAYS') === null ? null : (int) env('QA_RETENTION_DAYS'),
];
