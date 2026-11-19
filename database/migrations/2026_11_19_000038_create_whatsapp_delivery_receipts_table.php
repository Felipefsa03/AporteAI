<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_delivery_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('dedupe_key')->unique();
            $table->string('provider_message_id')->index();
            $table->uuid('outbox_id')->nullable();
            $table->string('status');
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->foreign('outbox_id')->references('id')->on('whatsapp_outbox_messages')->nullOnDelete();
            $table->index(['provider_message_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_delivery_receipts');
    }
};
