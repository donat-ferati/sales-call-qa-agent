<?php

namespace App\AI;

use App\AI\Exceptions\ProcessingException;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;

final class EvaluationResponseValidator
{
    public const DECISIONS = ['PASS', 'FAIL', 'INSUFFICIENT_EVIDENCE', 'NOT_APPLICABLE'];

    public const EVIDENCE_BASES = ['positive', 'contradictory', 'missing', 'uncertain'];

    public function __construct(private Factory $validation)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function validate(array $payload, array $criterionIds, array $segmentIds): array
    {
        $validator = $this->validation->make($payload, [
            'results' => ['required', 'array', 'size:'.count($criterionIds)],
            'results.*' => ['required', 'array:criterion_id,decision,explanation,evidence,evidence_basis,uncertainty'],
            'results.*.criterion_id' => ['required', 'integer', Rule::in($criterionIds)],
            'results.*.decision' => ['required', Rule::in(self::DECISIONS)],
            'results.*.explanation' => ['required', 'string', 'max:2000'],
            'results.*.evidence' => ['present', 'array', 'max:20'],
            'results.*.evidence.*' => ['required', 'array:segment_id,role'],
            'results.*.evidence.*.segment_id' => ['required', 'integer', Rule::in($segmentIds)],
            'results.*.evidence.*.role' => ['required', Rule::in(['supporting', 'contradicting'])],
            'results.*.evidence_basis' => ['required', Rule::in(self::EVIDENCE_BASES)],
            'results.*.uncertainty' => ['present', 'nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails() || array_keys($payload) !== ['results'] || !array_is_list($payload['results']))
        {
            throw new ProcessingException('The evaluator returned invalid structured results. No score was saved. Retry evaluation.');
        }

        $results = $validator->validated()['results'];
        $seen = [];

        foreach ($results as $result)
        {
            if (!is_int($result['criterion_id']) || isset($seen[$result['criterion_id']]))
            {
                throw new ProcessingException('The evaluator returned duplicate or invalid criterion identifiers. No score was saved.');
            }

            $seen[$result['criterion_id']] = true;

            if ($result['evidence_basis'] === 'uncertain' && $result['decision'] !== 'INSUFFICIENT_EVIDENCE')
            {
                throw new ProcessingException('Uncertain evidence requires an insufficient-evidence decision. No score was saved.');
            }

            if (!array_is_list($result['evidence']))
            {
                throw new ProcessingException('The evaluator returned an invalid evidence list. No score was saved.');
            }

            $references = [];
            $roles = [];

            foreach ($result['evidence'] as $evidence)
            {
                if (!is_int($evidence['segment_id']))
                {
                    throw new ProcessingException('The evaluator returned an invalid evidence identifier. No score was saved.');
                }
                $reference = $evidence['segment_id'].':'.$evidence['role'];

                if (isset($references[$reference]))
                {
                    throw new ProcessingException('The evaluator returned duplicate evidence references. No score was saved.');
                }
                $references[$reference] = true;
                $roles[] = $evidence['role'];
            }

            $requiredRole = match ($result['evidence_basis'])
            {
                'positive' => 'supporting',
                'contradictory' => 'contradicting',
                default => null,
            };

            if ($requiredRole !== null && !in_array($requiredRole, $roles, true))
            {
                throw new ProcessingException('The evaluator claimed '.$result['evidence_basis'].' evidence without a matching '.$requiredRole.' transcript reference. No score was saved.');
            }
        }

        return $results;
    }
}
