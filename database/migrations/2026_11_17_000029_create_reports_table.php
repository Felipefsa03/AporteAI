<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('investor_id');
            $table->string('label');
            $table->jsonb('payload');
            $table->binary('png');
            $table->timestampTz('created_at');

            $table->foreign('investor_id')->references('id')->on('investors')->restrictOnDelete();
            $table->index(['investor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
