<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_dead_letters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('dedupe_key')->unique();
            $table->string('reference_type');
            $table->uuid('reference_id');
            $table->text('reason');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->jsonb('payload')->nullable();
            $table->timestampTz('failed_at');
            $table->timestampsTz();

            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_dead_letters');
    }
};
