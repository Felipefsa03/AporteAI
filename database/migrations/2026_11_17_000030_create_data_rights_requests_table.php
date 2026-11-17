<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_rights_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('investor_id');
            $table->string('type');
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('resolved_at')->nullable();

            $table->foreign('investor_id')->references('id')->on('investors')->restrictOnDelete();
            $table->index(['investor_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_rights_requests');
    }
};
