<div>
    <x:page-heading title="Your review overview"
                    description="Follow processing, find the calls that need attention, and keep quality decisions traceable.">
        <x-slot:actions>
            <x:button :href="route('calls.create')">Upload calls</x:button>
        </x-slot:actions>
    </x:page-heading>
    <section aria-label="Workspace totals"
             class="mb-7 grid grid-cols-2 divide-x divide-slate-200 rounded-xl border border-slate-200 bg-white sm:grid-cols-3 xl:grid-cols-5">
        @foreach([['calls', 'Total calls'], ['completed', 'Completed evaluations'], ['pending', 'Processing'], ['failed', 'Failed attempts']] as [$key, $label])
            <div class="px-5 py-5"><p class="text-xs font-medium text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">{{ number_format($totals[$key]) }}</p></div>
        @endforeach
        <div class="px-5 py-5"><p class="text-xs font-medium text-slate-500">Average reviewed score</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">{{ $totals['reviewed_score'] !== null ? number_format($totals['reviewed_score'], 2).'%' : '—' }}</p>
        </div>
    </section>
    @if($totals['review_pending'])
        <div class="mb-7 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-teal-50 px-5 py-4">
            <div>
                <p class="text-sm font-semibold text-teal-950">{{ $totals['review_pending'] }} {{ Str::plural('evaluation', $totals['review_pending']) }}
                    ready for review</p>
                <p class="mt-1 text-xs text-teal-800">AI analysis is a starting point. Listen to the evidence and
                    confirm the result.</p></div>
            <a href="{{ route('calls.index', ['status' => 'completed']) }}"
               class="text-sm font-semibold text-teal-900 underline underline-offset-4">Open call library</a></div>
    @endif
    <x:panel title="Recent calls" description="The latest recordings and their current evaluation state.">
        @if($recent->count())
            <x:table.table>
                <x:table.head>
                    <x:table.row>
                        <x:table.header>Recording</x:table.header>
                        <x:table.header>Template</x:table.header>
                        <x:table.header>Processing</x:table.header>
                        <x:table.header>Score</x:table.header>
                        <x:table.header>Uploaded</x:table.header>
                    </x:table.row>
                </x:table.head>
                <x:table.body>
                    @foreach($recent as $evaluation)
                        <x:table.row class="hover:bg-slate-50">
                            <x:table.cell class="px-5 py-4"><a href="{{ route('evaluations.show', $evaluation) }}"
                                                               class="font-semibold hover:text-teal-800">{{ $evaluation->call->original_filename }}</a>
                                <p class="mt-1 text-xs text-slate-500">{{ gmdate('i:s', (int) $evaluation->call->duration) }}
                                    duration</p></x:table.cell>
                            <x:table.cell class="text-slate-600">{{ $evaluation->templateVersion->name }} <span
                                    class="text-xs text-slate-400">v{{ $evaluation->templateVersion->number }}</span>
                            </x:table.cell>
                            <x:table.cell class="px-5 py-4">
                                <x:badge :value="$evaluation->status"/>
                            </x:table.cell>
                            <x:table.cell class="tabular-nums"><span
                                    class="font-medium">{{ $evaluation->final_score !== null ? number_format($evaluation->final_score, 2).'%' : '—' }}</span>
                                @if($evaluation->status === 'completed')
                                    <p class="mt-1 text-xs text-slate-500">{{ $evaluation->review_status === 'reviewed' ? 'Reviewed' : 'Review pending' }}</p>
                                @endif</x:table.cell>
                            <x:table.cell
                                class="whitespace-nowrap text-xs text-slate-500">{{ $evaluation->created_at->diffForHumans() }}</x:table.cell>
                        </x:table.row>
                    @endforeach</x:table.body>
            </x:table.table>
        @else
            <x:empty title="Your first review starts here"
                     description="Create a QA template, then upload a recording. The transcript, evidence, and scorecard will appear together.">
                <div class="flex flex-wrap justify-center gap-2">
                    <x:button :href="route('templates.index')" variant="secondary">Browse QA templates</x:button>
                    <x:button :href="route('calls.create')">Upload calls</x:button>
                </div>
            </x:empty>
        @endif
    </x:panel>
    <div class="mt-5 flex justify-end"><a href="{{ route('calls.index') }}"
                                          class="text-sm font-medium text-teal-800 hover:underline">View all calls</a>
    </div>
</div>
