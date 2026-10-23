<?php

namespace App\Livewire\Templates;

use App\Actions\SaveTemplate;
use App\Livewire\Forms\TemplateForm;
use App\Models\QaTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Create extends Component
{
    public TemplateForm $form;

    public function mount(): void
    {
        Gate::authorize('create', QaTemplate::class);

        $this->form->addCriterion();
    }

    public function addCriterion(): void
    {
        $this->form->addCriterion();
    }

    public function removeCriterion(int $index): void
    {
        $this->form->removeCriterion($index);
    }

    public function moveCriterion(int $index, int $offset): void
    {
        $this->form->moveCriterion($index, $offset);
    }

    public function save(SaveTemplate $save): void
    {
        Gate::authorize('create', QaTemplate::class);

        $template = $save->handle(auth()->user(), $this->form->validate());

        session()->flash('status', 'Template draft saved. Preview and publish it when it is ready.');

        $this->redirectRoute('templates.show', $template);
    }

    public function render(): View
    {
        return view('livewire.templates.form', ['editing' => false])->layout('components.layouts.app', ['title' => 'New QA template']);
    }
}
