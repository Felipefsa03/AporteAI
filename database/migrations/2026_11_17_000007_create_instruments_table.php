<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instruments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('symbol')->unique();
            $table->string('isin')->nullable()->unique();
            $table->string('instrument_type');
            $table->string('issuer')->nullable();
            $table->date('maturity_date')->nullable();
            $table->string('status')->default('active');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instruments');
    }
};
