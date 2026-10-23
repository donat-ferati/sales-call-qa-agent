<?php

namespace App\Livewire\Templates;

use App\Actions\SaveTemplate;
use App\Livewire\Forms\TemplateForm;
use App\Models\QaTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Edit extends Component
{
    public QaTemplate $template;

    public TemplateForm $form;

    public function mount(QaTemplate $template): void
    {
        Gate::authorize('update', $template);

        $this->template = $template;
        $this->form->loadTemplate($template);
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
        Gate::authorize('update', $this->template);
        $template = $save->handle(auth()->user(), $this->form->validate(), $this->template);
        session()->flash('status', 'Draft saved. Historical evaluations keep their original criteria.');
        $this->redirectRoute('templates.show', $template);
    }

    public function render(): View
    {
        return view('livewire.templates.form', ['editing' => true])->layout('components.layouts.app', ['title' => 'Edit QA template']);
    }
}
