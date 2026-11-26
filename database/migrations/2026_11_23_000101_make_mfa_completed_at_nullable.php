<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE admin_sessions ALTER COLUMN mfa_completed_at DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE admin_sessions ALTER COLUMN mfa_completed_at SET NOT NULL');
    }
};
