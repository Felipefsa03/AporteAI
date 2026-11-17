<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('investor_id');
            $table->timestampTz('closed_at');
            $table->text('salesperson_note')->nullable();
            $table->timestampsTz();

            $table->foreign('investor_id')->references('id')->on('investors')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_records');
    }
};
