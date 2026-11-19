<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table): void {
            $table->timestampTz('last_inbound_at')->nullable()->after('last_message_at');
            $table->timestampTz('last_outbound_at')->nullable()->after('last_inbound_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table): void {
            $table->dropColumn(['last_inbound_at', 'last_outbound_at']);
        });
    }
};
