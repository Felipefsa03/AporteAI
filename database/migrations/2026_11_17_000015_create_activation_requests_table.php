<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activation_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('investor_id');
            $table->uuid('requested_by_admin_id');
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('blocked');
            $table->string('template_name');
            $table->string('bot_phone_number_id');
            $table->string('provider_message_id')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('first_reply_at')->nullable();
            $table->timestampsTz();

            $table->foreign('investor_id')->references('id')->on('investors')->restrictOnDelete();
            $table->foreign('requested_by_admin_id')->references('id')->on('admin_users')->restrictOnDelete();
            $table->index(['investor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_requests');
    }
};
