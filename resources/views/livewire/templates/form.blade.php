<div>
    <x:page-heading :title="$editing ? 'Edit QA template' : 'Create QA template'"
                    description="Define what good looks like. Every published version preserves its criteria for historical evaluations.">
        <x-slot:actions>
            <x:button variant="secondary" :href="route('templates.index')">Back to templates</x:button>
        </x-slot:actions>
    </x:page-heading>
    @if($errors->any())
        <div role="alert" class="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif
    <form wire:submit="save" class="space-y-6">
        <x:panel title="Template details">
            <div class="grid gap-5 p-5 lg:grid-cols-2">
                <x:form.input name="form.name" label="Template name" wire:model="form.name" maxlength="150" required
                              placeholder="e.g. First conversation quality"/>
                <x:form.textarea name="form.description" label="Description" wire:model="form.description" rows="2"
                                 placeholder="Explain when reviewers should use this template."/>
            </div>
        </x:panel>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="font-semibold">QA criteria</h2>
                <p class="mt-1 text-xs text-slate-500">PASS always means the standard was met. Weights determine each
                    criterion’s share of the score.</p></div>
            <x:button variant="secondary" wire:click="addCriterion" wire:loading.attr="disabled">Add criterion
            </x:button>
        </div>
        <x:form.error name="form.criteria"/>
        @foreach($form->criteria as $index => $criterion)
            <x:panel wire:key="criterion-form-{{ $index }}">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-3"><h3
                        class="text-sm font-semibold">Criterion {{ $index + 1 }}</h3>
                    <div class="flex items-center gap-1">
                        <x:button variant="quiet" wire:click="moveCriterion({{ $index }}, -1)" :disabled="$index === 0"
                                  aria-label="Move criterion up">Move up
                        </x:button>
                        <x:button variant="quiet" wire:click="moveCriterion({{ $index }}, 1)"
                                  :disabled="$index === count($form->criteria) - 1" aria-label="Move criterion down">
                            Move down
                        </x:button>
                        <x:button variant="quiet" wire:click="removeCriterion({{ $index }})">Remove</x:button>
                    </div>
                </div>
                <div class="grid gap-5 p-5 md:grid-cols-2">
                    <x:form.input :name="'form.criteria.'.$index.'.title'" label="Criterion title"
                                  wire:model="form.criteria.{{ $index }}.title" required
                                  placeholder="e.g. Clear introduction"/>
                    <x:form.input :name="'form.criteria.'.$index.'.question'" label="Evaluation question"
                                  wire:model="form.criteria.{{ $index }}.question" required
                                  placeholder="What should the evaluator look for?"/>
                    <x:form.textarea :name="'form.criteria.'.$index.'.pass_standard'" label="Standard required for PASS"
                                     wire:model="form.criteria.{{ $index }}.pass_standard" required
                                     help="Describe the desired behavior, including for negatively worded questions."/>
                    <x:form.textarea :name="'form.criteria.'.$index.'.instructions'" label="Evaluation instructions"
                                     wire:model="form.criteria.{{ $index }}.instructions"
                                     help="Optional context, examples, and boundaries."/>
                    <x:form.textarea :name="'form.criteria.'.$index.'.applicability'" label="Applicability"
                                     wire:model="form.criteria.{{ $index }}.applicability" rows="2"
                                     help="When does this criterion apply? Leave empty if it always applies."/>
                    <div class="space-y-5">
                        <x:form.input :name="'form.criteria.'.$index.'.weight'" label="Weight" type="number" min="1"
                                      max="100" wire:model="form.criteria.{{ $index }}.weight" required/>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox"
                                   wire:model="form.criteria.{{ $index }}.required"
                                   class="size-4 accent-teal-800">
                            Flag as required for reviewers
                        </label>
                        <p class="text-xs text-slate-500">Required flags do not add hidden scoring penalties.</p>
                    </div>
                </div>
            </x:panel>
        @endforeach
        <div class="flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 pt-5"><p
                class="text-xs text-slate-500">Save a draft, then preview and publish it.</p>
            <div class="flex gap-2">
                <x:button variant="secondary" :href="route('templates.index')">Cancel</x:button>
                <x:button type="submit" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove
                                                                                              wire:target="save">Save draft</span><span
                        wire:loading wire:target="save">Saving…</span></x:button>
            </div>
        </div>
    </form>
</div>
