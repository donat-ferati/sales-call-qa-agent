@props(['score' => null, 'provisional' => null, 'coverage' => 0, 'label'])
<div {{ $attributes }}>
    <p class="text-xs font-medium text-slate-500">{{ $label }}</p>
    <p class="mt-2 text-3xl font-semibold tabular-nums tracking-tight text-slate-950">
        @if($score !== null)
            {{ number_format($score, 2) }}<span class="ml-1 text-base text-slate-500">%</span>
        @elseif($provisional !== null)
            {{ number_format($provisional, 2) }}<span class="ml-1 text-base text-slate-500">%</span>
        @else
            <span class="text-lg">—</span>
        @endif</p>
    <p class="mt-1 text-xs leading-5 text-slate-500">
        @if($score !== null)
            Resolved applicable criteria
        @elseif($provisional !== null)
            Provisional · {{ number_format($coverage, 0) }}% weight resolved
        @else
            No resolved applicable criteria
        @endif
    </p>
</div>
