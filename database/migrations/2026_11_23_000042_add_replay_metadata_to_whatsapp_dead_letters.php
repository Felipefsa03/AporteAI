<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_dead_letters', function (Blueprint $table): void {
            $table->timestampTz('replayed_at')->nullable()->after('failed_at');
            $table->unsignedSmallInteger('replay_count')->default(0)->after('replayed_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_dead_letters', function (Blueprint $table): void {
            $table->dropColumn(['replayed_at', 'replay_count']);
        });
    }
};
