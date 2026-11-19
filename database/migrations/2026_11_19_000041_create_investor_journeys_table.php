<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_journeys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('investor_id')->unique();
            $table->string('state', 64)->default('new');
            $table->jsonb('payload')->nullable();
            $table->timestampTz('last_occurred_at')->nullable();
            $table->string('last_inbox_event_id')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('investor_id')->references('id')->on('investors')->cascadeOnDelete();
            $table->index(['state', 'last_occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_journeys');
    }
};
