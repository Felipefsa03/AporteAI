<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('created_by_admin_id');
            $table->string('full_name');
            $table->string('phone_e164')->unique();
            $table->string('email');
            $table->string('profession')->nullable();
            $table->decimal('income_monthly_brl', 18, 2)->nullable();
            $table->string('lifecycle_status')->default('draft');
            $table->text('source_note')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();

            $table->foreign('created_by_admin_id')->references('id')->on('admin_users')->restrictOnDelete();
            $table->index('lifecycle_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investors');
    }
};
