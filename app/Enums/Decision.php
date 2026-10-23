<?php

namespace App\Enums;

enum Decision: string
{
    case Pass = 'PASS';
    case Fail = 'FAIL';
    case InsufficientEvidence = 'INSUFFICIENT_EVIDENCE';
    case NotApplicable = 'NOT_APPLICABLE';
}
