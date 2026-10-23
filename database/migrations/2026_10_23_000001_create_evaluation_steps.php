<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table)
        {
            $table->unsignedTinyInteger('transcription_attempts')->default(0);
        });
        Schema::create('evaluation_runs', function (Blueprint $table)
        {
            $table->id();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('model_digest');
            $table->string('prompt_version');
            $table->json('criteria_snapshot');
            $table->json('segments_snapshot');
            $table->json('context');
            $table->unsignedInteger('context_tokens')->default(16384);
            $table->unsignedInteger('plan_revision')->default(1);
            $table->string('status')->default('running')->index();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['evaluation_id', 'fingerprint'], 'eval_run_fingerprint_unique');
        });
        Schema::create('evaluation_steps', function (Blueprint $table)
        {
            $table->id();
            $table->foreignId('evaluation_run_id')->constrained()->cascadeOnDelete();
            $table->string('step_key', 64);
            $table->string('kind');
            $table->string('status')->default('pending');
            $table->unsignedInteger('plan_revision');
            $table->unsignedInteger('context_tokens');
            $table->json('criterion_ids');
            $table->json('payload');
            $table->json('output')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('attempt_token')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['evaluation_run_id', 'step_key'], 'eval_step_key_unique');
            $table->index(['evaluation_run_id', 'status'], 'eval_step_run_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_steps');
        Schema::dropIfExists('evaluation_runs');
        Schema::table('evaluations', function (Blueprint $table)
        {
            $table->dropColumn('transcription_attempts');
        });
    }
};
