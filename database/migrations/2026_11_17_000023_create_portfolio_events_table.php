<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('portfolio_id');
            $table->uuid('investor_id');
            $table->string('event_type');
            $table->string('external_id');
            $table->uuid('instrument_id')->nullable();
            $table->bigInteger('amount_cents');
            $table->boolean('affects_positions')->default(true);
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->foreign('portfolio_id')->references('id')->on('portfolios')->restrictOnDelete();
            $table->foreign('instrument_id')->references('id')->on('instruments')->restrictOnDelete();
            $table->unique(['portfolio_id', 'external_id']);
            $table->index(['portfolio_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_events');
    }
};
