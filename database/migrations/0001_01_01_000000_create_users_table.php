<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table)
        {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('role')->default('manager');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        $timestamp = now();

        DB::table('users')->insert([
            [
                'name' => 'QA Administrator',
                'email' => 'admin@example.test',
                'role' => 'admin',
                'password' => Hash::make('password'),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'name' => 'QA Manager',
                'email' => 'manager@example.test',
                'role' => 'manager',
                'password' => Hash::make('password'),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        Schema::create('password_reset_tokens', function (Blueprint $table)
        {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table)
        {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
