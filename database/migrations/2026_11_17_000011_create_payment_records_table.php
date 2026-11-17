<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('subscription_id');
            $table->string('provider_ref')->unique();
            $table->unsignedSmallInteger('installment_number');
            $table->unsignedInteger('amount_cents');
            $table->timestampTz('due_at');
            $table->string('status')->default('pending');
            $table->timestampTz('paid_at')->nullable();
            $table->text('payment_url')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->timestampsTz();

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->restrictOnDelete();
            $table->index(['subscription_id', 'installment_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_records');
    }
};
