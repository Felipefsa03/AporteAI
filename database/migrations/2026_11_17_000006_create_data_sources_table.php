<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('dataset');
            $table->string('license');
            $table->boolean('usage_allowed')->default(false);
            $table->boolean('redistribution_allowed')->default(false);
            $table->jsonb('freshness_policy');
            $table->timestampTz('checked_at')->nullable();
            $table->string('status')->default('enabled');
            $table->timestampsTz();

            $table->unique(['provider', 'dataset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
