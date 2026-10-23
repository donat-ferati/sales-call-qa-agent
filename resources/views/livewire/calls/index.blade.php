<div>
    <x:page-heading title="Call library"
                    description="Find recordings, follow local processing, and open an evidence-backed review.">
        <x-slot:actions>
            <x:button :href="route('calls.create')">Upload calls</x:button>
        </x-slot:actions>
    </x:page-heading>
    <x:panel>
        <div class="grid gap-4 border-b border-slate-200 p-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <x:form.input name="search" label="Search recordings" wire:model.live.debounce.300ms="search"
                              placeholder="Search by filename"/>
            </div>
            <x:form.select name="status" label="Processing status" wire:model.live="status">
                <option value="">All statuses</option>
                @foreach(['queued', 'transcribing', 'evaluating', 'completed', 'failed'] as $option)
                    <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                @endforeach</x:form.select>
        </div>
        @if($evaluations->count())
            <x:table.table>
                <x:table.head>
                    <x:table.row>
                        <x:table.header>Recording</x:table.header>
                        <x:table.header>Template version</x:table.header>
                        <x:table.header>Status</x:table.header>
                        <x:table.header>Reviewed score</x:table.header>
                        <x:table.header>Uploaded</x:table.header>
                    </x:table.row>
                </x:table.head>
                <x:table.body>
                    @foreach($evaluations as $evaluation)
                        <x:table.row wire:key="evaluation-{{ $evaluation->id }}" class="hover:bg-slate-50">
                            <x:table.cell class="px-5 py-4"><a href="{{ route('evaluations.show', $evaluation) }}"
                                                               class="font-semibold hover:text-teal-800">{{ $evaluation->call->original_filename }}</a>
                                <p class="mt-1 text-xs text-slate-500">{{ gmdate('i:s', (int) $evaluation->call->duration) }}
                                    · {{ number_format($evaluation->call->size / 1048576, 1) }}
                                    MiB @unless($evaluation->call->is_complete)
                                        · Incomplete recording
                                    @endunless
                                </p>
                            </x:table.cell>
                            <x:table.cell class="text-slate-600">{{ $evaluation->templateVersion->name }}<p
                                    class="mt-1 text-xs text-slate-500">
                                    Version {{ $evaluation->templateVersion->number }}</p></x:table.cell>
                            <x:table.cell class="px-5 py-4">
                                <x:badge :value="$evaluation->status"/>
                            </x:table.cell>
                            <x:table.cell class="px-5 py-4"><p
                                    class="font-medium tabular-nums">{{ $evaluation->final_score !== null ? number_format($evaluation->final_score, 2).'%' : '—' }}</p>
                                @if($evaluation->status === 'completed')
                                    <p class="mt-1 text-xs text-slate-500">{{ $evaluation->review_status === 'reviewed' ? 'Reviewed' : 'Review pending' }}</p>
                                @endif</x:table.cell>
                            <x:table.cell
                                class="whitespace-nowrap text-xs text-slate-500">{{ $evaluation->created_at->format('M j, Y') }}
                                <p class="mt-1">{{ $evaluation->created_at->format('H:i') }}</p></x:table.cell>
                        </x:table.row>
                    @endforeach</x:table.body>
            </x:table.table>
            <div class="border-t border-slate-200 px-5 py-4">{{ $evaluations->links() }}</div>
        @else
            <x:empty title="No calls found"
                     description="Upload recordings to begin a review, or adjust your search filters.">
                <x:button :href="route('calls.create')">Upload calls</x:button>
            </x:empty>
        @endif
    </x:panel>
</div>
