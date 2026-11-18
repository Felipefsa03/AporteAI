<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Polymorphic actors include service names; subjects include market source keys.
        DB::statement('ALTER TABLE audit_events ALTER COLUMN actor_id TYPE varchar(255) USING actor_id::text');
        DB::statement('ALTER TABLE audit_events ALTER COLUMN subject_id TYPE varchar(255) USING subject_id::text');
    }

    public function down(): void
    {
        // PostgreSQL refuses a lossy conversion when named identifiers exist.
        // Preserve the audit trail; never delete rows to make rollback succeed.
        DB::statement('ALTER TABLE audit_events ALTER COLUMN actor_id TYPE uuid USING actor_id::uuid');
        DB::statement('ALTER TABLE audit_events ALTER COLUMN subject_id TYPE uuid USING subject_id::uuid');
    }
};
