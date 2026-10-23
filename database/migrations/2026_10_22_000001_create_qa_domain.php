<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('qa_templates', function (Blueprint $table)
        {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
            $table->index(['is_archived', 'name']);
        });
        Schema::create('template_versions', function (Blueprint $table)
        {
            $table->id();
            $table->foreignId('qa_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['qa_template_id', 'number']);
        });
        Schema::table('qa_templates', function (Blueprint $table)
        {
            $table->foreign('current_version_id')->references('id')->on('template_versions')->restrictOnDelete();
        });
        Schema::create('template_criteria', function (Blueprint $table)
        {
            $table->id();
            $table->foreignId('template_version_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('question');
            $table->text('instructions')->nullable();
            $table->text('pass_standard');
            $table->text('applicability')->nullable();
            $table->unsignedInteger('weight')->default(1);
            $table->boolean('required')->default(true);
            $table->string('scoring_type')->default('pass_fail');
            $table->unsignedInteger('display_order');
            $table->timestamps();
        });
        Schema::create('call_recordings', function (Blueprint $table)
        {
            $table->id();
            $table->string('original_filename');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->double('duration');
            $table->boolean('is_complete')->default(true);
            $table->string('status')->default('queued')->index();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->text('error_message')->nullable();
            $table->json('transcription_metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('transcript_segments', function (Blueprint $table)
        {
            $table->id();
            $table->foreignId('call_recording_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->double('start');
            $table->double('end');
            $table->text('text');
            $table->string('speaker')->nullable();
            $table->unique(['call_recording_id', 'sequence']);
        });
        Schema::create('evaluations', function (Blueprint $table)
        {
            $table->id();
            $table->foreignId('call_recording_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_version_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('queued')->index();
            $table->string('review_status')->default('pending')->index();
            $table->decimal('ai_score', 5, 2)->nullable();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->decimal('ai_provisional_score', 5, 2)->nullable();
            $table->decimal('final_provisional_score', 5, 2)->nullable();
            $table->decimal('ai_coverage', 5, 2)->default(0);
            $table->decimal('final_coverage', 5, 2)->default(0);
            $table->json('provider_metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->uuid('attempt_token')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('criterion_results', function (Blueprint $table)
        {
            $table->id();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_criterion_id')->constrained()->restrictOnDelete();
            $table->string('ai_decision');
            $table->string('final_decision');
            $table->text('ai_explanation');
            $table->text('review_explanation')->nullable();
            $table->text('uncertainty')->nullable();
            $table->string('evidence_basis');
            $table->string('evidence_validation_status')->default('validated');
            $table->unsignedInteger('revision')->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['evaluation_id', 'template_criterion_id']);
        });
        Schema::create('criterion_evidence', function (Blueprint $table)
        {
            $table->id();
            $table->foreignId('criterion_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transcript_segment_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->unique(['criterion_result_id', 'transcript_segment_id', 'role'], 'evidence_segment_role_unique');
        });
        Schema::create('score_overrides', function (Blueprint $table)
        {
            $table->id();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('criterion_result_id')->constrained()->cascadeOnDelete();
            $table->string('previous_decision');
            $table->string('new_decision');
            $table->text('reason');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['score_overrides', 'criterion_evidence', 'criterion_results', 'evaluations', 'transcript_segments', 'call_recordings', 'template_criteria'] as $table)
        {
            Schema::dropIfExists($table);
        }
        Schema::table('qa_templates', fn (Blueprint $table) => $table->dropForeign(['current_version_id']));
        Schema::dropIfExists('template_versions');
        Schema::dropIfExists('qa_templates');
    }
};
