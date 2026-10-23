<div @if($polling) wire:poll.3s @endif>
    <x:page-heading :title="'Upload batch #'.$batch->id" description="Follow file preparation and open each recording’s analysis as it becomes available.">
        <x-slot:actions><x:button variant="secondary" :href="route('calls.index')">Call library</x:button><x:button :href="route('calls.create')">Upload calls</x:button></x-slot:actions>
    </x:page-heading>
    <div class="mb-6 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-500">
        <x:badge :value="$batch->status" /><span>{{ $batch->templateVersion->name }} · v{{ $batch->templateVersion->number }}</span><span>{{ $batch->source_count }} source files · {{ number_format($batch->source_bytes / 1048576, 1) }} MiB</span><span>Submitted by {{ $batch->user->name }}</span>
    </div>
    @if($errors->any())<div role="alert" class="mb-5 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>@endif
    @if($batch->error_message)
        <div role="alert" class="mb-6 rounded-xl bg-amber-50 p-5 text-sm text-amber-950"><p>{{ $batch->error_message }}</p>@if($batch->status === 'failed')<x:button wire:click="retry" wire:loading.attr="disabled" class="mt-4">Retry interrupted preparation</x:button>@endif</div>
    @endif
    @if(in_array($batch->status, ['queued', 'preparing'], true))
        <div class="mb-6 rounded-xl border border-teal-200 bg-teal-50 px-5 py-4" role="status"><p class="text-sm font-semibold text-teal-950">{{ $batch->status === 'queued' ? 'Waiting for upload preparation' : 'Checking files and preparing archives' }}</p><p class="mt-1 text-xs leading-6 text-teal-800">This report updates automatically. Accepted recordings can begin analysis while the rest of the batch is prepared.</p></div>
    @elseif($batch->status === 'prepared')
        <div class="mb-6 rounded-xl bg-teal-50 px-5 py-4 text-sm text-teal-950"><p class="font-semibold">File preparation complete</p><p class="mt-1 text-xs leading-6 text-teal-800">{{ $analyzing ? $analyzing.' recordings are queued or being analyzed locally.' : 'Open an evaluation to inspect its evidence and review the result.' }} Rejected files need a corrected upload; completed recordings are kept.</p></div>
    @endif
    @unless($batch->is_complete)<p class="mb-6 rounded-xl bg-amber-50 px-5 py-4 text-sm text-amber-900">All recordings in this batch are marked incomplete. Missing audio limits conclusions about omitted behavior.</p>@endunless
    <dl class="mb-6 grid grid-cols-2 gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-5">
        <div><dt class="text-xs text-slate-500">Accepted recordings</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{{ $accepted->count() }}</dd></div><div><dt class="text-xs text-slate-500">Analysis complete</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{{ $completed }}</dd></div><div><dt class="text-xs text-slate-500">Analysis failed</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{{ $failed }}</dd></div><div><dt class="text-xs text-slate-500">Rejected items</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{{ $rejected }}</dd></div><div><dt class="text-xs text-slate-500">Skipped entries</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{{ $skipped }}</dd></div>
    </dl>
    <x:panel title="Files and results" description="ZIP paths identify source entries only. Recordings are stored under generated private filenames.">
        <x:table.table><x:table.head><x:table.row><x:table.header>Source or recording</x:table.header><x:table.header>Preparation</x:table.header><x:table.header>Analysis</x:table.header><x:table.header>Result</x:table.header></x:table.row></x:table.head><x:table.body>
            @foreach($sources as $source)
                @include('livewire.calls.batches.item', ['item' => $source, 'archiveName' => null])
                @foreach($batch->items->where('parent_id', $source->id)->sortBy('archive_index') as $child)
                    @include('livewire.calls.batches.item', ['item' => $child, 'archiveName' => $source->original_name])
                @endforeach
            @endforeach
        </x:table.body></x:table.table>
    </x:panel>
</div>
