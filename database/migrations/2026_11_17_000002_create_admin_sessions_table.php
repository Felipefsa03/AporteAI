<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('admin_user_id');
            $table->string('token_hash')->unique();
            $table->timestampTz('mfa_completed_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('admin_user_id')->references('id')->on('admin_users')->restrictOnDelete();
            $table->index(['admin_user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_sessions');
    }
};
