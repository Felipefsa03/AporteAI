<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->string('external_id')->unique();
            $table->string('direction');
            $table->string('type');
            $table->text('encrypted_content')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->foreign('conversation_id')->references('id')->on('whatsapp_conversations')->restrictOnDelete();
            $table->index(['conversation_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
