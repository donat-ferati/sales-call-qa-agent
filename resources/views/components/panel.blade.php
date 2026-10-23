@props(['title' => null, 'description' => null])
<section {{ $attributes->class('rounded-xl border border-slate-200 bg-white') }}>
    @if($title)
        <div class="border-b border-slate-200 px-5 py-4"><h2 class="font-semibold text-slate-950">{{ $title }}</h2>
            @if($description)
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ $description }}</p>
            @endif
        </div>
    @endif
    {{ $slot }}
</section>
