<?php

namespace Database\Seeders;

use App\Actions\PublishTemplate;
use App\Actions\SaveTemplate;
use App\Models\QaTemplate;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::whereIn('role', ['admin', 'manager'])->firstOrFail();
        Auth::login($user);

        $templates = [
            'Contact Centre Fundamentals — Demonstration' => [
                ['Introduction', 'Did the agent introduce the organisation and themselves?', 'PASS means the agent identifies the organisation and themselves.'],
                ['Request acknowledgement', 'Did the agent acknowledge or invite the caller’s request?', 'PASS means the agent invites the request or clearly acknowledges what the caller needs.'],
                ['Answer or next step', 'Did the agent answer the request or explain an actionable next step?', 'PASS means the request is answered or a concrete next step is explained before closing. An incomplete recording cannot establish an omission.'],
                ['Courteous closing', 'Did the agent close courteously?', 'PASS means the agent closes with a courteous acknowledgment or goodbye. An unavailable ending means insufficient evidence.'],
            ],
            'Life Insurance QA — Illustrative' => [
                ['Introduction', 'Did the agent introduce themselves and their organisation?', 'PASS means both are clearly introduced by the agent.'],
                ['Permission to continue', 'Did the agent seek permission to continue?', 'PASS means the customer is given a clear opportunity to agree to continue.'],
                ['Pricing clarity', 'Did the agent explain pricing clearly?', 'PASS means premium amounts and payment frequency are explained without ambiguity.'],
                ['Exclusions explained', 'Did the agent explain applicable policy exclusions?', 'PASS means relevant exclusions are explained by the agent. A customer mentioning exclusions is not sufficient.'],
                ['No pressure', 'Did the agent use high-pressure tactics?', 'PASS means the agent respects the customer’s choice without coercion or pressure. The scoring standard is positive despite the question wording.'],
                ['Next steps', 'Did the agent explain what happens next?', 'PASS means the agent gives clear, actionable next steps.'],
            ],
        ];

        foreach ($templates as $name => $criteria)
        {
            if (QaTemplate::where('name', $name)->exists())
            {
                continue;
            }

            $template = app(SaveTemplate::class)->handle($user, [
                'name' => $name, 'description' => 'Illustrative demonstration rubric. Not an official company checklist or regulatory compliance assessment.',
                'criteria' => array_map(fn ($criterion) => ['title' => $criterion[0], 'question' => $criterion[1], 'pass_standard' => $criterion[2], 'instructions' => 'Use the whole recording and validated segment evidence. Attribute statements only when context supports it.', 'applicability' => 'Use NOT_APPLICABLE only when clearly irrelevant, never merely because evidence is absent.', 'weight' => 1, 'required' => true], $criteria),
            ]);

            app(PublishTemplate::class)->handle($template);
        }
    }
}
