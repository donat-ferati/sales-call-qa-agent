<div>
    <x:page-heading title="QA templates"
                    description="Reusable standards for every conversation. Published criteria stay attached to the calls they scored.">
        <x-slot:actions>
            <x:button :href="route('templates.create')">Create template</x:button>
        </x-slot:actions>
    </x:page-heading>
    <x:panel>
        <div class="grid gap-4 border-b border-slate-200 p-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <x:form.input name="search" label="Search templates" wire:model.live.debounce.300ms="search"
                              placeholder="Search by name"/>
            </div>
            <x:form.select name="status" label="Status" wire:model.live="status">
                <option value="active">Active templates</option>
                <option value="archived">Archived templates</option>
                <option value="all">All templates</option>
            </x:form.select>
        </div>
        @if($templates->count())
            <x:table.table>
                <x:table.head>
                    <x:table.row>
                        <x:table.header>Template</x:table.header>
                        <x:table.header>Status</x:table.header>
                        <x:table.header>Version</x:table.header>
                        <x:table.header>Updated</x:table.header>
                        <x:table.header><span class="sr-only">Actions</span></x:table.header>
                    </x:table.row>
                </x:table.head>
                <x:table.body>
                    @foreach($templates as $template)
                        <x:table.row wire:key="template-{{ $template->id }}" class="hover:bg-slate-50">
                            <x:table.cell class="max-w-sm px-5 py-4"><a href="{{ route('templates.show', $template) }}"
                                                                        class="font-semibold text-slate-900 hover:text-teal-800">{{ $template->name }}</a>
                                <p class="mt-1 line-clamp-1 text-xs text-slate-500">{{ $template->description }}</p>
                            </x:table.cell>
                            <x:table.cell class="px-5 py-4">
                                <x:badge
                                    :value="$template->is_archived ? 'archived' : ($template->current_version_id ? 'published' : 'draft')"/>
                            </x:table.cell>
                            <x:table.cell
                                class="whitespace-nowrap text-slate-600">{{ $template->currentVersion ? 'v'.$template->currentVersion->number : 'Not published' }}
                                <p class="mt-1 text-xs text-slate-400">{{ $template->versions_count }} saved
                                    versions</p></x:table.cell>
                            <x:table.cell
                                class="whitespace-nowrap text-xs text-slate-500">{{ $template->updated_at->diffForHumans() }}</x:table.cell>
                            <x:table.cell class="px-5 py-4">
                                <div class="flex justify-end gap-3 whitespace-nowrap text-xs font-medium"><a
                                        href="{{ route('templates.edit', $template) }}"
                                        class="text-teal-800 hover:underline">Edit</a>
                                    <button wire:click="duplicate({{ $template->id }})"
                                            class="text-slate-600 hover:underline">Duplicate
                                    </button>
                                    @unless($template->is_archived)
                                        <button wire:click="archive({{ $template->id }})"
                                                wire:confirm="Archive this template? Historical evaluations will stay available."
                                                class="text-slate-600 hover:underline">Archive
                                        </button>
                                    @endunless</div>
                            </x:table.cell>
                        </x:table.row>
                    @endforeach
                </x:table.body>
            </x:table.table>
            <div class="border-t border-slate-200 px-5 py-4">{{ $templates->links() }}</div>
        @else
            <x:empty title="No templates found"
                     description="Create a template with the standards your reviewers should use, or adjust your search.">
                <x:button :href="route('templates.create')">Create a template</x:button>
            </x:empty>
        @endif
    </x:panel>
</div>
