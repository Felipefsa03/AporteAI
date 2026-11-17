<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('instrument_id');
            $table->uuid('source_id');
            $table->timestampTz('as_of');
            $table->decimal('price', 18, 6);
            $table->string('quality_status')->default('valid');
            $table->timestampTz('captured_at');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('instrument_id')->references('id')->on('instruments')->restrictOnDelete();
            $table->foreign('source_id')->references('id')->on('data_sources')->restrictOnDelete();
            $table->unique(['instrument_id', 'source_id', 'as_of'], 'market_quotes_idempotency');
            $table->index(['quality_status', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_quotes');
    }
};
