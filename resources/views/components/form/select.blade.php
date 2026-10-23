@props(['label', 'name', 'help' => null])
<div>
    <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium text-slate-700">{{ $label }}</label>
    <select id="{{ $name }}"
            name="{{ $name }}" {{ $attributes->class('block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900') }}>{{ $slot }}</select>
    @if($help)
        <p class="mt-1.5 text-xs leading-5 text-slate-500">{{ $help }}</p>
    @endif
    <x:form.error :name="$name"/>
</div>
