<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->text('mfa_secret_encrypted');
            $table->boolean('mfa_enabled')->default(false);
            $table->boolean('active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();

            $table->comment('Conta humana unica do proprietario; nenhuma conta de investidor existe no MVP.');
        });

        // RB-026: no MVP so pode existir uma conta humana ativa.
        DB::statement('CREATE UNIQUE INDEX admin_users_single_active ON admin_users ((active)) WHERE active = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
