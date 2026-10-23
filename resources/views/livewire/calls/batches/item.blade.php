<x:table.row wire:key="upload-item-{{ $item->id }}" :class="$item->kind === 'zip' ? 'bg-slate-50' : ''">
    <x:table.cell class="max-w-sm align-top">
        <p class="break-all text-sm font-medium text-slate-800">{{ $item->entry_path ?? $item->original_name }}</p>
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ number_format($item->size / 1048576, 2) }} MiB @if($archiveName) · From {{ $archiveName }} @elseif($item->kind === 'zip') · ZIP archive @endif</p>
        @if($item->error_message)<p class="mt-2 text-xs leading-6 text-rose-700">{{ $item->error_message }}</p>@endif
    </x:table.cell>
    <x:table.cell class="align-top"><x:badge :value="$item->status" /></x:table.cell>
    <x:table.cell class="align-top">@if($item->evaluation)<x:badge :value="$item->evaluation->status" />@elseif($item->kind !== 'zip' && $item->status === 'queued')<span class="text-xs text-slate-500">Recording no longer available</span>@else<span class="text-slate-400">—</span>@endif</x:table.cell>
    <x:table.cell class="align-top">@if($item->evaluation)<a href="{{ route('evaluations.show', $item->evaluation) }}" class="whitespace-nowrap text-sm font-semibold text-teal-800 hover:underline">Open evaluation</a>@else<span class="text-slate-400">—</span>@endif</x:table.cell>
</x:table.row>
