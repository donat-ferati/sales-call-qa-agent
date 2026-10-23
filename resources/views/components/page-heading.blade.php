@props(['title', 'description' => null])
<div {{ $attributes->class('mb-7 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between') }}>
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-950">{{ $title }}</h1>
        @if($description)
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{{ $description }}</p>
        @endif</div>
    @if(isset($actions))
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endif
</div>
