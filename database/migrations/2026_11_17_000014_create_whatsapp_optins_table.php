<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_optins', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('investor_id');
            $table->string('idempotency_key')->unique();
            $table->string('phone_e164');
            $table->string('brand')->default('AporteAI');
            $table->string('channel')->default('whatsapp');
            $table->text('evidence_uri');
            $table->timestampTz('accepted_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('investor_id')->references('id')->on('investors')->restrictOnDelete();
            $table->index(['investor_id', 'phone_e164']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_optins');
    }
};
