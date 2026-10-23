@props(['variant' => 'primary', 'type' => 'button', 'href' => null])
@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg px-3.5 py-2.5 text-sm font-semibold transition-colors disabled:cursor-wait disabled:opacity-50';
    $variantClass = match ($variant) {
        'secondary' => 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
        'danger' => 'bg-rose-700 text-white hover:bg-rose-800',
        'quiet' => 'text-slate-600 hover:bg-slate-100 hover:text-slate-950',
        default => 'bg-teal-800 text-white hover:bg-teal-900',
    };
@endphp
@if($href)
    <a href="{{ $href }}" {{ $attributes->class([$base, $variantClass]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class([$base, $variantClass]) }}>{{ $slot }}</button>
@endif
