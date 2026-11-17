<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('investor_id');
            $table->string('plan_code');
            $table->string('status')->default('pending_activation');
            $table->string('access_status')->default('suspended');
            $table->timestampTz('due_at')->nullable();
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampsTz();

            $table->foreign('investor_id')->references('id')->on('investors')->restrictOnDelete();
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
