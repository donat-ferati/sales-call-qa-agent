<?php

namespace App\Console\Commands;

use App\Actions\UploadCall;
use App\Models\Evaluation;
use App\Models\QaTemplate;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

final class ProcessDemo extends Command
{
    protected $signature = 'qa:demo {--sample=branch-hours-complete.wav} {--incomplete} {--force}';

    protected $description = 'Queue an actual public demo recording for local transcription and evaluation.';

    public function handle(UploadCall $upload): int
    {
        $sample = $this->option('sample');

        if (!in_array($sample, ['branch-hours-complete.wav', 'card-replacement-missing-next-step.wav', 'branch-hours-truncated-demo.wav'], true))
        {
            $this->error('Choose a bundled sample filename.');

            return self::FAILURE;
        }

        if (!$this->option('force') && Evaluation::whereHas('call', fn ($query) => $query->where('original_filename', $sample))->exists())
        {
            $this->info('This sample is already queued or evaluated. Use --force for a separate evaluation.');

            return self::SUCCESS;
        }

        $user = User::whereIn('role', ['admin', 'manager'])->firstOrFail();

        Auth::login($user);

        $template = QaTemplate::where('name', 'Contact Centre Fundamentals — Demonstration')->firstOrFail();
        $file = new UploadedFile(base_path('samples/'.$sample), $sample, 'audio/wav', null, true);
        $evaluation = $upload->handle($user, $file, $template->id, !$this->option('incomplete') && $sample !== 'branch-hours-truncated-demo.wav');

        $this->info('Evaluation '.$evaluation->id.' queued. Run php artisan queue:work --queue=audio --stop-when-empty --timeout=0.');

        return self::SUCCESS;
    }
}
