<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('explanations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('investor_id');
            $table->string('model')->nullable();
            $table->string('prompt_hash');
            $table->string('allocation_hash');
            $table->jsonb('sources');
            $table->string('rule_version');
            $table->text('body');
            $table->timestampTz('created_at');

            $table->foreign('investor_id')->references('id')->on('investors')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('explanations');
    }
};
