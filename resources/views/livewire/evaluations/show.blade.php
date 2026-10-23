<div x-data="callPlayer" x-on:seek-evidence="seek($event.detail.id, $event.detail.time)"
     @if(in_array($evaluation->status, ['queued', 'transcribing', 'evaluating'], true)) wire:poll.3s @endif>
    <x:page-heading :title="$evaluation->call->original_filename"
                    description="Listen to the evidence, review each judgment, and record the final decision.">
        <x-slot:actions>
            <x:button variant="secondary" :href="route('calls.index')">Back to call library</x:button>
        </x-slot:actions>
    </x:page-heading>
    <div class="mb-6 flex flex-wrap items-center gap-x-5 gap-y-3 text-xs text-slate-500">
        <x:badge :value="$evaluation->status"/>
        <span>{{ gmdate('i:s', (int) $evaluation->call->duration) }} duration</span><span>Uploaded {{ $evaluation->created_at->format('M j, Y · H:i') }}</span>
        @if($evaluation->status === 'completed')
            <x:badge :value="$evaluation->review_status"/>
        @endif
    </div>
    @if($errors->any())
        <div class="mb-5 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800"
             role="alert">{{ $errors->first() }}</div>
    @endif
    @if($evaluation->status === 'failed')
        <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-5"><h2 class="font-semibold text-rose-900">
                Processing needs attention</h2>
            <p class="mt-2 text-sm leading-6 text-rose-800">{{ $evaluation->error_message }}</p>
            <div class="mt-4 flex flex-wrap items-center gap-4">
                <x:button wire:click="retry" wire:loading.attr="disabled">Retry processing</x:button>
                <p class="text-xs text-rose-800">Completed transcription and saved evaluation checkpoints are reused when available.</p></div>
        </div>
    @endif
    @if(in_array($evaluation->status, ['queued', 'transcribing', 'evaluating'], true))
        <div class="mb-6 rounded-xl border border-teal-200 bg-teal-50 px-5 py-4" role="status"><p
                class="text-sm font-semibold text-teal-950">{{ match($evaluation->status) { 'queued' => 'Waiting for the local worker', 'transcribing' => 'Transcribing your recording', 'evaluating' => 'Evaluating the transcript', default => 'Processing' } }}</p>
            @if($evaluation->status === 'evaluating' && $processingProgress !== null)
                <p class="mt-2 text-sm font-medium text-teal-950">{{ $processingProgress['message'] }}</p>
                @if($processingProgress['total'] > 0)
                    <p class="mt-1 text-xs leading-5 text-teal-800">
                        <span class="tabular-nums">{{ $processingProgress['completed'] }} of {{ $processingProgress['total'] }}</span>
                        transcript windows complete.
                    </p>
                @endif
            @endif
            <p class="mt-1 text-xs leading-5 text-teal-800">This page updates automatically. Local processing may take
                several minutes; you can safely return later.</p></div>
    @endif
    @unless($evaluation->call->is_complete)
        <div class="mb-6 rounded-xl bg-amber-50 px-5 py-4 text-sm text-amber-900"><strong>Incomplete recording.</strong>
            Missing audio limits what can be concluded. Absence of a statement is not proof it never happened.
        </div>
    @endunless
    <div class="grid items-start gap-6 xl:grid-cols-2">
        <div class="space-y-5">
            <x:panel title="Recording">
                <div class="p-5">
                    <div wire:ignore>
                        <audio x-ref="audio" controls preload="metadata"
                               x-on:timeupdate="currentTime = $event.target.currentTime; selected = null"
                               src="{{ route('calls.audio', $evaluation->call) }}">Your browser does not support audio
                            playback.
                        </audio>
                    </div>
                    <p class="mt-3 text-xs text-slate-500">Position
                        <span class="font-medium tabular-nums text-slate-700"
                              x-text="timestamp(currentTime)">0:00</span>
                        · Select evidence or a transcript timestamp to seek.</p>
                </div>
            </x:panel>
            <x:panel title="Transcript"
                     description="Generated from the uploaded audio. Speaker identity is shown only when reliably available.">
                @if($segments->count())
                    <div x-ref="transcript" role="region" aria-label="Call transcript" tabindex="0"
                         class="max-h-96 overflow-y-auto overscroll-contain focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 lg:max-h-128">
                        <ol class="divide-y divide-slate-100">
                            @foreach($segments as $segment)
                                <li wire:key="segment-{{ $segment->id }}" data-segment="{{ $segment->id }}"
                                    class="flex gap-3 px-5 py-4 transition-colors"
                                    :class="selected === {{ $segment->id }} || (currentTime >= {{ $segment->start }} && currentTime < {{ $segment->end }}) ? 'bg-teal-50' : ''">
                                    <button type="button" @click="seek({{ $segment->id }}, {{ $segment->start }})"
                                            class="mt-0.5 shrink-0 self-start rounded text-xs font-semibold tabular-nums text-teal-800 hover:underline"
                                            aria-label="Seek to {{ gmdate('i:s', (int) $segment->start) }}">{{ gmdate('i:s', (int) $segment->start) }}</button>
                                    <div class="min-w-0">
                                        @if($segment->speaker)
                                            <p class="mb-1 text-xs font-semibold text-slate-500">{{ $segment->speaker }}</p>
                                        @endif<p class="text-sm leading-7 text-slate-700">{{ $segment->text }}</p></div>
                                </li>
                            @endforeach</ol>
                    </div>
                @else
                    <x:empty title="Transcript not ready yet"
                             description="Timestamped segments appear here after local transcription succeeds."/>
                @endif
            </x:panel>
        </div>
        <div class="space-y-5">
            <x:panel title="QA scorecard" description="PASS means the criterion’s intended standard was met.">
                <div class="grid grid-cols-2 gap-5 border-b border-slate-200 p-5">
                    <x:score label="Original AI score" :score="$evaluation->ai_score"
                             :provisional="$evaluation->ai_provisional_score"
                             :coverage="$evaluation->ai_coverage ?? 0"/>
                    <x:score label="Current review score" :score="$evaluation->final_score"
                             :provisional="$evaluation->final_provisional_score"
                             :coverage="$evaluation->final_coverage ?? 0"/>
                </div>
                @if($evaluation->status === 'completed')
                    <div class="px-5 py-4 text-xs leading-6 text-slate-500">
                        @if($results->every(fn ($result) => $result->final_decision === 'NOT_APPLICABLE'))
                            <p class="font-medium text-slate-700">No applicable criteria.</p>
                        @elseif($unresolved)
                            <p class="font-medium text-amber-800">{{ $unresolved }} {{ Str::plural('criterion', $unresolved) }}
                                need resolution before a final score.</p>
                        @else
                            <p>All applicable criteria are resolved. The score uses their configured weights.</p>
                        @endif<p>Not-applicable criteria are excluded. Insufficient evidence is never silently counted
                            as pass or fail.</p></div>
                @else
                    <div class="px-5 py-4 text-xs leading-6 text-slate-500">Scores appear only after evaluation and
                        evidence validation complete.
                    </div>
                @endif
            </x:panel>
            @forelse($results as $result)
                <x:panel wire:key="result-{{ $result->id }}">
                    <div class="p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0"><h2
                                    class="font-semibold text-slate-950">{{ $result->criterion->title }}</h2>
                                <p class="mt-1 text-xs text-slate-500">Weight {{ $result->criterion->weight }}
                                    @if($result->criterion->required)
                                        · Required for review
                                    @endif</p></div>
                            <x:badge :value="$result->final_decision"/>
                        </div>
                        <p class="mt-4 text-sm leading-6 text-slate-600">{{ $result->criterion->question }}</p>
                        <details class="mt-3 text-xs text-slate-500">
                            <summary class="cursor-pointer font-medium">View PASS standard</summary>
                            <p class="mt-2 leading-6">{{ $result->criterion->pass_standard }}</p>
                            @if($result->criterion->applicability)
                                <p class="mt-2 leading-6">Applies when: {{ $result->criterion->applicability }}</p>
                            @endif</details>
                        <div class="mt-5 border-t border-slate-100 pt-4"><p
                                class="text-xs font-semibold text-slate-500">AI judgment · <span
                                    class="font-medium">{{ Str::headline(strtolower($result->ai_decision)) }}</span></p>
                            <p class="mt-2 text-sm leading-6 text-slate-700">{{ $result->ai_explanation }}</p>
                            @if($result->uncertainty)
                                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900">{{ $result->uncertainty }}</p>
                            @endif</div>
                        @if($result->evidence->count())
                            <div class="mt-4 space-y-2">
                                @foreach($result->evidence as $evidence)
                                    <button type="button"
                                            @click="$dispatch('seek-evidence', { id: {{ $evidence->segment->id }}, time: {{ $evidence->segment->start }} })"
                                            class="block w-full rounded-lg bg-slate-50 p-3 text-left hover:bg-teal-50">
                                        <span class="flex flex-wrap items-center gap-2 text-xs"><span
                                                class="font-semibold tabular-nums text-teal-800">{{ gmdate('i:s', (int) $evidence->segment->start) }}–{{ gmdate('i:s', (int) $evidence->segment->end) }}</span><span
                                                class="text-slate-500">{{ ucfirst($evidence->role) }} evidence</span></span><span
                                            class="mt-2 block text-sm leading-6 text-slate-600">“{{ $evidence->segment->text }}”</span>
                                    </button>
                                @endforeach</div>
                        @else
                            <p class="mt-4 text-xs leading-5 text-slate-500">{{ match($result->evidence_basis) { 'missing' => 'No specific quotation can establish an omission. Review the complete transcript and recording.', 'uncertain' => 'No reliable supporting segment was identified. Review the recording before deciding.', default => 'No transcript quotation is attached to this judgment.' } }}</p>
                        @endif
                        @if($result->reviewed_at)
                            <div class="mt-4 rounded-lg bg-teal-50 px-3 py-3"><p
                                    class="text-xs font-semibold text-teal-950">Human review
                                    · {{ $result->reviewer?->name ?? 'Reviewer' }}</p>
                                <p class="mt-1 text-sm leading-6 text-teal-900">{{ $result->review_explanation }}</p>
                                <p class="mt-1 text-xs text-teal-800">{{ $result->reviewed_at->format('M j, Y · H:i') }}</p>
                            </div>
                        @endif
                        @if($editingResultId === $result->id)
                            <form wire:submit="saveOverride"
                                  class="mt-5 space-y-4 rounded-lg border border-slate-200 p-4"><h3
                                    class="text-sm font-semibold">Review this criterion</h3>
                                <x:form.select name="newDecision" label="Reviewed decision" wire:model="newDecision"
                                               required>
                                    @foreach($decisions as $decision)
                                        <option
                                            value="{{ $decision->value }}">{{ Str::headline(strtolower($decision->value)) }}</option>
                                    @endforeach</x:form.select>
                                <x:form.textarea name="reason" label="Reason for this decision" wire:model="reason"
                                                 required rows="3"
                                                 help="Explain what you heard. This reason is retained in the review history."/>
                                <div class="flex flex-wrap gap-2">
                                    <x:button type="submit" wire:loading.attr="disabled" wire:target="saveOverride">Save
                                        review decision
                                    </x:button>
                                    <x:button variant="secondary" wire:click="cancelOverride">Cancel</x:button>
                                </div>
                            </form>
                        @else
                            <div class="mt-5">
                                <x:button variant="secondary" wire:click="startOverride({{ $result->id }})"
                                          wire:loading.attr="disabled">Review decision
                                </x:button>
                            </div>
                        @endif
                        @if($result->overrides->count())
                            <details class="mt-4 border-t border-slate-100 pt-4">
                                <summary class="cursor-pointer text-xs font-medium text-slate-500">Decision history
                                    · {{ $result->overrides->count() }} {{ Str::plural('revision', $result->overrides->count()) }}</summary>
                                <ol class="mt-3 space-y-4">
                                    @foreach($result->overrides->sortByDesc('id') as $override)
                                        <li class="text-xs leading-5 text-slate-500"><p
                                                class="font-medium text-slate-700">{{ Str::headline(strtolower($override->previous_decision)) }}
                                                → {{ Str::headline(strtolower($override->new_decision)) }}</p>
                                            <p class="mt-1">{{ $override->reason }}</p>
                                            <p class="mt-1">{{ $override->user->name }}
                                                · {{ $override->created_at->format('M j, Y · H:i') }}</p></li>
                                    @endforeach</ol>
                            </details>
                        @endif
                    </div>
                </x:panel>
            @empty
                <x:panel>
                    <x:empty title="Scorecard is being prepared"
                             description="Each template criterion receives its own judgment, explanation, and validated evidence after evaluation completes."/>
                </x:panel>
            @endforelse
            @if($evaluation->status === 'completed')
                <x:panel>
                    <div class="flex flex-wrap items-center justify-between gap-4 p-5">
                        <div><h2
                                class="text-sm font-semibold">{{ $evaluation->review_status === 'reviewed' ? 'Review completed' : 'Complete your review' }}</h2>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                @if($evaluation->reviewed_at)
                                    {{ $evaluation->reviewer?->name ?? 'Reviewer' }}
                                    · {{ $evaluation->reviewed_at->format('M j, Y · H:i') }}
                                @else
                                    Review all criteria and resolve insufficient evidence before confirming.
                                @endif</p></div>
                        @if($evaluation->review_status !== 'reviewed')
                            <x:button wire:click="markReviewed" wire:loading.attr="disabled"
                                      :disabled="$unresolved > 0">Mark reviewed
                            </x:button>
                        @endif</div>
                </x:panel>
            @endif
            @if($evaluation->provider_metadata)
                <details class="px-1 text-xs text-slate-500">
                    <summary class="cursor-pointer font-medium">Analysis details</summary>
                    <dl class="mt-3 space-y-2">
                        @foreach(['provider' => 'Provider', 'model' => 'Model', 'model_digest' => 'Model digest', 'prompt_version' => 'Prompt version', 'elapsed_seconds' => 'Evaluation seconds'] as $key => $label)
                            @if(isset($evaluation->provider_metadata[$key]))
                                <div>
                                    <dt class="font-medium">{{ $label }}</dt>
                                    <dd class="mt-0.5 break-all">{{ $evaluation->provider_metadata[$key] }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                </details>
            @endif
        </div>
    </div>
</div>
