<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('actor_type');
            $table->uuid('actor_id');
            $table->string('action');
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->uuid('trace_id');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('occurred_at');

            $table->index(['subject_type', 'subject_id']);
            $table->index('trace_id');

            $table->comment('Trilha imutavel por convencao de aplicacao; trigger de somente-leitura e politica de retencao entram na migracao de producao.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
