<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('call_recordings', function (Blueprint $table)
        {
            $table->uuid('import_key')->nullable()->unique();
        });

        Schema::create('call_upload_batches', function (Blueprint $table)
        {
            $table->id();
            $table->uuid('storage_key')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('qa_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('template_version_id')->constrained()->restrictOnDelete();
            $table->boolean('is_complete');
            $table->unsignedInteger('source_count');
            $table->unsignedBigInteger('source_bytes');
            $table->string('status')->default('queued')->index();
            $table->uuid('attempt_token')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('call_upload_items', function (Blueprint $table)
        {
            $table->id();
            $table->foreignId('call_upload_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('call_upload_items')->cascadeOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('kind');
            $table->string('original_name', 1000);
            $table->string('entry_path', 1000)->nullable();
            $table->unsignedInteger('archive_index')->nullable();
            $table->string('stored_path')->nullable();
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('expanded_bytes')->nullable();
            $table->unsignedInteger('archive_entries')->default(0);
            $table->string('status')->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->foreignId('evaluation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['parent_id', 'archive_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_upload_items');
        Schema::dropIfExists('call_upload_batches');
        Schema::table('call_recordings', function (Blueprint $table)
        {
            $table->dropUnique(['import_key']);
            $table->dropColumn('import_key');
        });
    }
};
