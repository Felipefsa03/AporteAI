<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', static function (Blueprint $table): void {
            $table->string('google_sub')->nullable()->unique()->after('email');
            $table->string('mfa_phone_e164')->nullable()->after('mfa_enabled');
        });

        Schema::create('admin_mfa_challenges', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('admin_user_id');
            $table->string('token_hash')->unique();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('admin_user_id')->references('id')->on('admin_users')->restrictOnDelete();
            $table->index(['admin_user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_mfa_challenges');

        Schema::table('admin_users', static function (Blueprint $table): void {
            $table->dropUnique(['google_sub']);
            $table->dropColumn(['google_sub', 'mfa_phone_e164']);
        });
    }
};
