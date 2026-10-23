<?php

namespace App\Actions;

use App\Models\QaTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveTemplate
{
    public static function rules(): array
    {
        return [
            'name' => 'required|string|max:150', 'description' => 'nullable|string|max:2000',
            'criteria' => 'required|array|min:1|max:20',
            'criteria.*.title' => 'required|string|max:150',
            'criteria.*.question' => 'required|string|max:1000',
            'criteria.*.instructions' => 'nullable|string|max:1500',
            'criteria.*.pass_standard' => 'required|string|max:1500',
            'criteria.*.applicability' => 'nullable|string|max:1000',
            'criteria.*.weight' => 'required|integer|min:1|max:100',
            'criteria.*.required' => 'required|boolean',
        ];
    }

    public function handle(User $user, array $data, ?QaTemplate $template = null): QaTemplate
    {
        Gate::forUser($user)->authorize($template ? 'update' : 'create', $template ?? QaTemplate::class);

        $validated = Validator::make($data, self::rules())->validate();

        return DB::transaction(function () use ($user, $validated, $template)
        {
            if ($template)
            {
                $template = QaTemplate::query()->lockForUpdate()->findOrFail($template->id);

                if ($template->is_archived)
                {
                    throw ValidationException::withMessages(['name' => 'Archived templates cannot be edited. Duplicate the template instead.']);
                }

                $template->update(['name' => $validated['name'], 'description' => $validated['description'] ?? null]);
            }
            else
            {
                $template = QaTemplate::create(['name' => $validated['name'], 'description' => $validated['description'] ?? null, 'created_by' => $user->id]);
            }

            $version = $template->versions()->whereNull('published_at')->first();

            if (!$version)
            {
                $version = $template->versions()->create(['number' => ($template->versions()->max('number') ?? 0) + 1, 'name' => $template->name, 'description' => $template->description]);
            }
            else
            {
                $version->update(['name' => $template->name, 'description' => $template->description]);
                $version->criteria()->delete();
            }

            foreach ($validated['criteria'] as $order => $criterion)
            {
                $version->criteria()->create([
                    'title' => $criterion['title'], 'question' => $criterion['question'],
                    'instructions' => $criterion['instructions'] ?? null,
                    'pass_standard' => $criterion['pass_standard'], 'applicability' => $criterion['applicability'] ?? null,
                    'weight' => (int) $criterion['weight'], 'required' => (bool) $criterion['required'],
                    'scoring_type' => 'pass_fail', 'display_order' => $order,
                ]);
            }

            return $template->fresh();
        });
    }
}
