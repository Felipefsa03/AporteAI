<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suitability_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('investor_id');
            $table->unsignedInteger('version');
            $table->jsonb('answers');
            $table->string('classification')->default('incomplete');
            $table->string('status')->default('draft');
            $table->timestampTz('assessed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->foreign('investor_id')->references('id')->on('investors')->restrictOnDelete();
            $table->unique(['investor_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suitability_assessments');
    }
};
