<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aporte_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('investor_id');
            $table->bigInteger('amount_cents');
            $table->string('phase')->default('PHASE1');
            $table->jsonb('answers')->default('{}');
            $table->jsonb('proposal')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->foreign('investor_id')->references('id')->on('investors')->restrictOnDelete();
            $table->index(['investor_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aporte_sessions');
    }
};
