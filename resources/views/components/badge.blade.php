@props(['value'])
@php
    $label = match ($value) {
        'PASS' => 'Pass', 'FAIL' => 'Fail', 'INSUFFICIENT_EVIDENCE' => 'Insufficient evidence', 'NOT_APPLICABLE' => 'Not applicable',
        'transcript_ready' => 'Transcript ready', 'pending' => 'Review pending', default => ucfirst(str_replace('_', ' ', $value)),
    };
    $color = match ($value) {
        'PASS', 'completed', 'reviewed', 'published' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        'FAIL', 'failed', 'archived' => 'bg-rose-50 text-rose-800 ring-rose-200',
        'INSUFFICIENT_EVIDENCE', 'pending', 'queued', 'transcribing', 'evaluating' => 'bg-amber-50 text-amber-900 ring-amber-200',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
@endphp
<span {{ $attributes->class(['inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset', $color]) }}>{{ $label }}</span>
