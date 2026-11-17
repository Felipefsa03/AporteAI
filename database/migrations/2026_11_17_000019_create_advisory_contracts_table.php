<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisory_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('investor_id');
            $table->unsignedInteger('version');
            $table->string('status')->default('pending');
            $table->timestampTz('signed_at')->nullable();
            $table->text('artifact_uri');
            $table->text('privacy_notice_uri');
            $table->timestampsTz();

            $table->foreign('investor_id')->references('id')->on('investors')->restrictOnDelete();
            $table->unique(['investor_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisory_contracts');
    }
};
