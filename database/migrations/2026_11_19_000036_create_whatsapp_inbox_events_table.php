<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_inbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('dedupe_key')->unique();
            $table->string('external_id')->nullable();
            $table->string('kind');
            $table->string('phone_number_id')->nullable();
            $table->string('from_number_e164')->nullable();
            $table->string('message_type')->nullable();
            $table->string('provider_status')->nullable();
            $table->string('payload_hash', 64);
            $table->jsonb('payload');
            $table->string('state')->default('processed');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->index(['kind', 'received_at']);
            $table->index(['external_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_inbox_events');
    }
};
