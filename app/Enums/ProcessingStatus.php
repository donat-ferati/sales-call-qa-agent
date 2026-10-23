<?php

namespace App\Enums;

enum ProcessingStatus: string
{
    case Queued = 'queued';
    case Transcribing = 'transcribing';
    case TranscriptReady = 'transcript_ready';
    case Evaluating = 'evaluating';
    case Completed = 'completed';
    case Failed = 'failed';
}
