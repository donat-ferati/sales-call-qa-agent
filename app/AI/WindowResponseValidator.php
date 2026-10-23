<?php

namespace App\AI;

use App\AI\Exceptions\ProcessingException;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;

final class WindowResponseValidator
{
    public const STATUSES = ['not_observed', 'supporting', 'contradicting', 'uncertain', 'mixed'];

    public function __construct(private Factory $validation)
    {
    }

    public function validate(array $payload, array $criterionIds, array $segmentIds): array
    {
        $validator = $this->validation->make($payload, [
            'observations' => ['required', 'array', 'size:'.count($criterionIds)],
            'observations.*' => ['required', 'array:criterion_id,status,evidence,explanation,uncertainty,needs_context,applicability'],
            'observations.*.criterion_id' => ['required', 'integer', Rule::in($criterionIds)],
            'observations.*.status' => ['required', Rule::in(self::STATUSES)],
            'observations.*.evidence' => ['present', 'array', 'max:20'],
            'observations.*.evidence.*' => ['required', 'array:segment_id,role'],
            'observations.*.evidence.*.segment_id' => ['required', 'integer', Rule::in($segmentIds)],
            'observations.*.evidence.*.role' => ['required', Rule::in(['supporting', 'contradicting'])],
            'observations.*.explanation' => ['required', 'string', 'max:1000'],
            'observations.*.uncertainty' => ['present', 'nullable', 'string', 'max:1000'],
            'observations.*.needs_context' => ['required', 'boolean'],
            'observations.*.applicability' => ['required', Rule::in(['applicable', 'not_applicable', 'unknown'])],
        ]);

        if ($validator->fails() || array_keys($payload) !== ['observations'] || !array_is_list($payload['observations']))
        {
            throw new ProcessingException('The evaluator returned invalid structured window observations. No score was saved.');
        }

        $observations = $validator->validated()['observations'];
        $seen = [];

        foreach ($observations as $observation)
        {
            if (!is_int($observation['criterion_id']) || isset($seen[$observation['criterion_id']]) || !is_bool($observation['needs_context']) || !array_is_list($observation['evidence']))
            {
                throw new ProcessingException('Window observation identifiers, booleans or evidence types are invalid.');
            }

            $seen[$observation['criterion_id']] = true;
            $roles = [];
            $references = [];

            foreach ($observation['evidence'] as $evidence)
            {
                $reference = $evidence['segment_id'].':'.$evidence['role'];

                if (!is_int($evidence['segment_id']) || isset($references[$reference]))
                {
                    throw new ProcessingException('Window evidence contains invalid or duplicate references.');
                }

                $references[$reference] = true;
                $roles[] = $evidence['role'];
            }

            if ($observation['status'] === 'not_observed' && $observation['evidence'] !== [])
            {
                throw new ProcessingException('A not-observed window finding cannot claim positive or contradictory evidence.');
            }

            $requiredRoles = match ($observation['status'])
            {
                'supporting' => ['supporting'],
                'contradicting' => ['contradicting'],
                'mixed' => ['supporting', 'contradicting'],
                default => [],
            };

            if (array_diff($requiredRoles, $roles))
            {
                throw new ProcessingException('The window finding is missing its claimed supporting or contradictory evidence.');
            }

            if (($observation['status'] === 'uncertain' || $observation['needs_context']) && (!is_string($observation['uncertainty']) || trim($observation['uncertainty']) === ''))
            {
                throw new ProcessingException('An uncertain window finding must explain its uncertainty or missing context.');
            }
        }

        return $observations;
    }
}
