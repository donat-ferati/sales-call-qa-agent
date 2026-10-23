<?php

namespace App\Providers;

use App\AI\Contracts\EvaluationProvider;
use App\AI\Contracts\TranscriptionProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TranscriptionProvider::class, fn ($app) => $app->make(config('qa.transcription_provider')));
        $this->app->bind(EvaluationProvider::class, fn ($app) => $app->make(config('qa.evaluation_provider')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
