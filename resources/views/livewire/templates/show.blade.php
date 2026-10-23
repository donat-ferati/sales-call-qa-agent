<div>
    <x:page-heading :title="$version ? $version->name : $template->name"
                    :description="$version ? $version->description : $template->description">
        <x-slot:actions>
            @if($isLatest)
                <x:button variant="secondary" wire:click="duplicate" wire:loading.attr="disabled">Duplicate</x:button>
                @unless($template->is_archived)
                    <x:button variant="secondary" :href="route('templates.edit', $template)">Edit draft</x:button>
                    @if($version && !$version->published_at)
                        <x:button wire:click="publish" wire:loading.attr="disabled">Publish v{{ $version->number }}</x:button>
                    @endif
                @endunless
            @else
                <x:button variant="secondary" :href="route('templates.show', $template)">View latest version</x:button>
            @endif
        </x-slot:actions>
    </x:page-heading>
    @if($errors->any())
        <div role="alert" class="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif
    <div class="mb-6 flex flex-wrap items-center gap-3 text-xs text-slate-500">
        @if($template->is_archived)
            <x:badge value="archived"/>
        @endif
        <x:badge :value="$version?->published_at ? 'published' : 'draft'"/>
        @if($version)
            <span>Previewing version {{ $version->number }}</span>
        @endif
        <span>{{ $isLatest ? 'Published versions are immutable.' : 'Read-only historical version.' }}</span>
    </div>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            @if($version)
                @foreach($version->criteria->sortBy('display_order') as $criterion)
                    <x:panel>
                        <div class="p-5">
                            <div class="flex items-start justify-between gap-4">
                                <h2 class="font-semibold">{{ $criterion->title }}</h2>
                                <span class="shrink-0 text-xs text-slate-500">Weight {{ $criterion->weight }}</span>
                            </div>
                            <p class="mt-3 text-sm text-slate-600">{{ $criterion->question }}</p>
                            <dl class="mt-5 space-y-4 text-sm">
                                <div>
                                    <dt class="text-xs font-semibold text-teal-800">PASS standard</dt>
                                    <dd class="mt-1 leading-6 text-slate-700">{{ $criterion->pass_standard }}</dd>
                                </div>
                                @if($criterion->instructions)
                                    <div>
                                        <dt class="text-xs font-semibold text-slate-500">Instructions</dt>
                                        <dd class="mt-1 leading-6 text-slate-600">{{ $criterion->instructions }}</dd>
                                    </div>
                                @endif
                                @if($criterion->applicability)
                                    <div>
                                        <dt class="text-xs font-semibold text-slate-500">Applicability</dt>
                                        <dd class="mt-1 leading-6 text-slate-600">{{ $criterion->applicability }}</dd>
                                    </div>
                                @endif
                            </dl>
                            @if($criterion->required)
                                <p class="mt-4 text-xs font-medium text-slate-500">Required for reviewer attention</p>
                            @endif
                        </div>
                    </x:panel>
                @endforeach
            @else
                <x:panel>
                    <x:empty title="No criteria yet" description="Edit this template to add its first criterion."/>
                </x:panel>
            @endif
        </div>
        <div>
            <x:panel title="Version history">
                <nav aria-label="Template versions">
                    <ul class="divide-y divide-slate-100">
                        @foreach($versions as $savedVersion)
                            <li>
                                <a href="{{ route('templates.show', ['template' => $template->id, 'version' => $savedVersion->id]) }}"
                                   @class(['flex items-center justify-between gap-3 px-5 py-4 hover:bg-slate-50', 'bg-teal-50 text-teal-950' => $version?->id === $savedVersion->id])
                                   @if($version?->id === $savedVersion->id) aria-current="page" @endif>
                                    <div>
                                        <p class="text-sm font-medium">Version {{ $savedVersion->number }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $savedVersion->created_at->format('M j, Y · H:i') }}</p>
                                    </div>
                                    <x:badge :value="$savedVersion->published_at ? 'published' : 'draft'"/>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </x:panel>
            @if($isLatest && $publishedVersion && !$template->is_archived)
                <x:button :href="route('calls.create', ['template' => $template->id])" class="mt-4 w-full">
                    Evaluate with published v{{ $publishedVersion->number }}
                </x:button>
            @endif
        </div>
    </div>
</div>
