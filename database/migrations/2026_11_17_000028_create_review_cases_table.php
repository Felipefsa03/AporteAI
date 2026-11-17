<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('investor_id');
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->string('reason')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('critical')->default(true);
            $table->jsonb('snapshot')->default('{}');
            $table->jsonb('sources')->default('[]');
            $table->jsonb('rules')->default('[]');
            $table->text('justification')->nullable();
            $table->uuid('resolved_by_admin_id')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('resolved_at')->nullable();

            $table->foreign('investor_id')->references('id')->on('investors')->restrictOnDelete();
            $table->foreign('resolved_by_admin_id')->references('id')->on('admin_users')->restrictOnDelete();
            $table->index(['status', 'critical']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_cases');
    }
};
