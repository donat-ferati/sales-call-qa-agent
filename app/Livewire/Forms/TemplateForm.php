<?php

namespace App\Livewire\Forms;

use App\Actions\SaveTemplate;
use App\Models\QaTemplate;
use Livewire\Form;

class TemplateForm extends Form
{
    public string $name = '';

    public string $description = '';

    public array $criteria = [];

    public function rules(): array
    {
        return SaveTemplate::rules();
    }

    public function loadTemplate(QaTemplate $template): void
    {
        $this->name = $template->name;
        $this->description = $template->description ?? '';
        $version = $template->versions()->orderByDesc('number')->with('criteria')->first();

        $this->criteria = $version ? $version->criteria->sortBy('display_order')->map(fn ($criterion) => [
            'title' => $criterion->title,
            'question' => $criterion->question,
            'instructions' => $criterion->instructions ?? '',
            'pass_standard' => $criterion->pass_standard,
            'applicability' => $criterion->applicability ?? '',
            'weight' => $criterion->weight,
            'required' => $criterion->required,
        ])->values()->all() : [];
    }

    public function addCriterion(): void
    {
        if (count($this->criteria) >= 20)
        {
            return;
        }

        $this->criteria[] = ['title' => '', 'question' => '', 'instructions' => '', 'pass_standard' => '', 'applicability' => '', 'weight' => 1, 'required' => true];
    }

    public function removeCriterion(int $index): void
    {
        unset($this->criteria[$index]);

        $this->criteria = array_values($this->criteria);
    }

    public function moveCriterion(int $index, int $offset): void
    {
        $target = $index + $offset;

        if (!in_array($offset, [-1, 1], true) || !array_key_exists($index, $this->criteria) || !array_key_exists($target, $this->criteria))
        {
            return;
        }

        [$this->criteria[$index], $this->criteria[$target]] = [$this->criteria[$target], $this->criteria[$index]];
    }
}
