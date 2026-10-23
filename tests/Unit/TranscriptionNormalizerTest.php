<?php

namespace Tests\Unit;

use App\AI\Exceptions\ProcessingException;
use App\AI\TranscriptionNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranscriptionNormalizerTest extends TestCase
{
    public function test_it_converts_real_whisper_millisecond_offsets_and_does_not_invent_speakers(): void
    {
        $result = (new TranscriptionNormalizer())->normalize(['transcription' => [
            ['offsets' => ['from' => 1250, 'to' => 3400], 'text' => ' Hello there. '],
            ['offsets' => ['from' => 3500, 'to' => 5250], 'text' => ' How can I help?'],
        ]], 6.0, 'en', ['provider' => 'whisper.cpp']);

        $this->assertSame('Hello there. How can I help?', $result->text);
        $this->assertSame(1.25, $result->segments[0]['start']);
        $this->assertSame(3.4, $result->segments[0]['end']);
        $this->assertNull($result->segments[0]['speaker']);
        $this->assertSame(2, $result->segments[1]['sequence']);
        $this->assertArrayNotHasKey('confidence', $result->segments[0]);
    }

    #[DataProvider('invalidSegments')]
    public function test_it_rejects_missing_or_invalid_timestamped_speech(array $segments): void
    {
        $this->expectException(ProcessingException::class);
        (new TranscriptionNormalizer())->normalize(['transcription' => $segments], 5.0, 'en');
    }

    public static function invalidSegments(): array
    {
        return [
            'no speech' => [[]],
            'missing offsets' => [[['text' => 'Hello']]],
            'negative start' => [[['offsets' => ['from' => -100, 'to' => 2000], 'text' => 'Hello']]],
            'inverted bounds' => [[['offsets' => ['from' => 2000, 'to' => 1000], 'text' => 'Hello']]],
            'past recording' => [[['offsets' => ['from' => 1000, 'to' => 6000], 'text' => 'Hello']]],
            'out of order' => [[['offsets' => ['from' => 2000, 'to' => 3000], 'text' => 'Later'], ['offsets' => ['from' => 1000, 'to' => 2000], 'text' => 'Earlier']]],
            'wrong time type' => [[['offsets' => ['from' => '1000', 'to' => 2000], 'text' => 'Hello']]],
            'empty text' => [[['offsets' => ['from' => 0, 'to' => 2000], 'text' => '  ']]],
        ];
    }

    public function test_only_subframe_final_timestamp_rounding_is_limited_to_real_duration(): void
    {
        $result = (new TranscriptionNormalizer())->normalize(['transcription' => [
            ['offsets' => ['from' => 0, 'to' => 5020], 'text' => 'Hello'],
        ]], 5.0, 'en');

        $this->assertSame(5.0, $result->segments[0]['end']);
    }
}
