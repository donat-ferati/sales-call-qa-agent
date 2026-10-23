<div>
    <x:page-heading title="Upload calls" description="Choose one QA standard for a batch of recordings, ZIP files, or a mixed selection.">
        <x-slot:actions>
            <x:button variant="secondary" :href="route('calls.index')">Back to call library</x:button>
        </x-slot:actions>
    </x:page-heading>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            @if($templates->isEmpty())
                <x:panel>
                    <x:empty title="Publish a template first" description="Every recording needs a published QA template. Create and publish its criteria to continue.">
                        <x:button :href="route('templates.create')">Create QA template</x:button>
                    </x:empty>
                </x:panel>
            @else
                <form wire:submit="submitBatch" class="space-y-5">
                    @if($errors->any())
                        <div class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ $errors->first() }}</div>
                    @endif
                    <x:panel title="Review standard">
                        <div class="p-5">
                            <x:form.select name="templateId" label="QA template" wire:model="templateId" required help="One published version is captured when you submit. Every accepted recording in this batch uses that version.">
                                <option value="">Choose a published template</option>
                                @foreach($templates as $template)
                                    <option value="{{ $template->id }}">{{ $template->name }} · v{{ $template->currentVersion->number }}</option>
                                @endforeach
                            </x:form.select>
                        </div>
                    </x:panel>
                    <x:panel title="Recordings and ZIP files">
                        <div class="space-y-5 p-5"
                             x-data="{ uploading: false, progress: 0, selectionError: '', checkSelection(event) {
                                const files = Array.from(event.target.files);
                                this.selectionError = files.length > 20 ? 'Choose up to 20 source files.' : files.some(file => file.size > 104857600) ? 'Each selected source file must be 100 MiB or smaller.' : files.reduce((total, file) => total + file.size, 0) > 262144000 ? 'The selected files exceed the 250 MiB batch limit.' : '';
                                if (this.selectionError) { event.stopImmediatePropagation(); event.target.value = ''; }
                             } }"
                             x-on:livewire-upload-start="uploading = true; progress = 0"
                             x-on:livewire-upload-finish="uploading = false"
                             x-on:livewire-upload-error="uploading = false; selectionError = 'The upload did not finish. Check file sizes and try again.'"
                             x-on:livewire-upload-cancel="uploading = false"
                             x-on:livewire-upload-progress="progress = $event.detail.progress">
                            <label for="sources" class="block rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center">
                                <span class="block text-sm font-semibold text-slate-800">Select recordings, ZIP files, or both</span>
                                <span class="mt-2 block text-xs leading-5 text-slate-500">MP3, WAV, M4A, OGG and ZIP · up to 20 selected files · 250 MiB combined</span>
                                <input id="sources" type="file" wire:model="sources" multiple accept=".mp3,.wav,.m4a,.ogg,.zip,audio/*,application/zip" x-on:change.capture="checkSelection($event)"
                                       class="mt-5 block w-full text-xs text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-teal-800 file:px-4 file:py-2 file:font-semibold file:text-white" required>
                            </label>
                            <p x-show="selectionError" x-text="selectionError" x-cloak role="alert" class="text-sm text-rose-700"></p>
                            <x:form.error name="sources" />
                            <div x-show="uploading" x-cloak role="status" class="space-y-2">
                                <p class="text-sm text-teal-800">Uploading selected files · <span x-text="progress"></span>%</p>
                                <progress max="100" :value="progress" aria-label="File upload progress" class="h-2 w-full accent-teal-800"></progress>
                            </div>
                            @if(count($sources))
                                <div>
                                    <p class="mb-3 text-xs font-medium text-slate-500">{{ count($sources) }} {{ Str::plural('source file', count($sources)) }} · {{ number_format(collect($sources)->sum(fn ($source) => $source->getSize()) / 1048576, 1) }} MiB</p>
                                    <ul class="max-h-80 divide-y divide-slate-200 overflow-y-auto rounded-lg border border-slate-200">
                                        @foreach($sources as $index => $source)
                                            <li wire:key="source-{{ $source->getFilename() }}" class="flex items-start justify-between gap-3 px-4 py-3">
                                                <div class="min-w-0"><p class="break-all text-sm font-medium text-slate-800">{{ $source->getClientOriginalName() }}</p><p class="mt-1 text-xs text-slate-500">{{ number_format($source->getSize() / 1048576, 2) }} MiB · Validation runs after submission</p><x:form.error :name="'sources.'.$index" /></div>
                                                <button type="button" wire:click="removeSource({{ $index }})" wire:loading.attr="disabled" wire:target="sources,submitBatch" class="shrink-0 rounded text-xs font-medium text-slate-600 hover:text-rose-700" aria-label="Remove {{ $source->getClientOriginalName() }}">Remove</button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            <label class="flex items-start gap-3 text-sm text-slate-700">
                                <input type="checkbox" wire:model="isComplete" class="mt-0.5 size-4 shrink-0 accent-teal-800">
                                <span>All selected recordings are complete<span class="mt-1 block text-xs leading-5 text-slate-500">This applies to direct audio files and every audio entry in ZIP files. Clear it if any recording is incomplete, or upload recordings with different completeness settings in separate batches.</span></span>
                            </label>
                            <x:form.error name="isComplete" />
                        </div>
                    </x:panel>
                    <div class="flex justify-end">
                        <x:button type="submit" wire:loading.attr="disabled" wire:target="sources,submitBatch">
                            <span wire:loading.remove wire:target="submitBatch">Upload and evaluate calls</span>
                            <span wire:loading wire:target="submitBatch">Saving upload batch…</span>
                        </x:button>
                    </div>
                </form>
            @endif
        </div>
        <aside class="space-y-5">
            <x:panel title="Batch limits">
                <dl class="space-y-4 p-5 text-sm">
                    <div><dt class="font-medium">Each recording</dt><dd class="mt-1 text-xs leading-5 text-slate-500">25 MiB or smaller, up to 15 minutes.</dd></div>
                    <div><dt class="font-medium">Each ZIP</dt><dd class="mt-1 text-xs leading-5 text-slate-500">Up to 100 MiB. Ordinary unencrypted ZIPs; archives containing nested ZIPs are rejected.</dd></div>
                    <div><dt class="font-medium">Across the batch</dt><dd class="mt-1 text-xs leading-5 text-slate-500">Up to 50 recordings, 200 archive entries and 500 MiB of expanded data.</dd></div>
                </dl>
            </x:panel>
            <x:panel title="Every recording is tracked">
                <div class="space-y-3 p-5 text-xs leading-6 text-slate-500"><p>Files are checked and archives are prepared in the background. Valid recordings continue even if another source is rejected.</p><p>The batch report shows file-level reasons and links to each evaluation. Local transcription and analysis process recordings one at a time.</p><p>Audio and transcripts remain in private local storage. Only upload material you are authorized to process.</p></div>
            </x:panel>
        </aside>
    </div>

    @if($recentBatches->count())
        <div class="mt-8">
            <x:panel title="Recent upload batches" description="Return to preparation details and individual evaluation results.">
                <x:table.table><x:table.head><x:table.row><x:table.header>Batch</x:table.header><x:table.header>Template version</x:table.header><x:table.header>Preparation</x:table.header><x:table.header>Submitted</x:table.header></x:table.row></x:table.head><x:table.body>
                    @foreach($recentBatches as $recentBatch)
                        <x:table.row class="hover:bg-slate-50"><x:table.cell><a href="{{ route('calls.batches.show', $recentBatch) }}" class="font-semibold text-teal-800 hover:underline">Batch #{{ $recentBatch->id }}</a><p class="mt-1 text-xs text-slate-500">{{ $recentBatch->source_count }} source files · {{ number_format($recentBatch->source_bytes / 1048576, 1) }} MiB</p></x:table.cell><x:table.cell>{{ $recentBatch->templateVersion->name }} · v{{ $recentBatch->templateVersion->number }}</x:table.cell><x:table.cell><x:badge :value="$recentBatch->status" /></x:table.cell><x:table.cell class="whitespace-nowrap text-xs text-slate-500">{{ $recentBatch->created_at->format('M j, Y · H:i') }}</x:table.cell></x:table.row>
                    @endforeach
                </x:table.body></x:table.table>
            </x:panel>
        </div>
    @endif
</div>
