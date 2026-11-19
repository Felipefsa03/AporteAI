<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inbox_event_id')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->uuid('investor_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('to_number_e164');
            $table->string('bot_phone_number_id');
            $table->string('type');
            $table->jsonb('payload');
            $table->string('status')->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->string('provider_message_id')->nullable()->unique();
            $table->text('last_error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->foreign('inbox_event_id')->references('id')->on('whatsapp_inbox_events')->nullOnDelete();
            $table->foreign('conversation_id')->references('id')->on('whatsapp_conversations')->nullOnDelete();
            $table->foreign('investor_id')->references('id')->on('investors')->nullOnDelete();
            $table->index(['status', 'available_at']);
            $table->index(['to_number_e164', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_outbox_messages');
    }
};
