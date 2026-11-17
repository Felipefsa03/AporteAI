<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestampTz('captured_at');
            $table->jsonb('metadata_jsonb')->nullable();
            $table->timestampsTz();

            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_snapshots');
    }
};
