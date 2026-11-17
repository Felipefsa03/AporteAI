<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('investor_id');
            $table->string('name')->default('Carteira principal');
            $table->string('status')->default('active');
            $table->timestampsTz();

            $table->foreign('investor_id')->references('id')->on('investors')->restrictOnDelete();
            $table->unique(['investor_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolios');
    }
};
