<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('portfolio_id');
            $table->uuid('investor_id');
            $table->jsonb('allocation');
            $table->string('status')->default('proposed');
            $table->text('proposal_uri')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('portfolio_id')->references('id')->on('portfolios')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_recommendations');
    }
};
