@props(['title', 'description'])
<div {{ $attributes->class('px-6 py-14 text-center') }}><h3 class="font-semibold text-slate-800">{{ $title }}</h3>
    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">{{ $description }}</p>
    @if(!$slot->isEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
