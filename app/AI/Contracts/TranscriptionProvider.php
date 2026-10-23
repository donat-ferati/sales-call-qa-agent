<?php

namespace App\AI\Contracts;

use App\AI\Data\TranscriptionResult;

interface TranscriptionProvider
{
    public function transcribe(string $absoluteAudioPath): TranscriptionResult;
}
