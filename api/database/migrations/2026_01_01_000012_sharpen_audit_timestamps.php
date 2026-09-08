<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Microseconds on the audit log.
 *
 * A whole-second timestamp makes the log lie about order: two changes a tenth of a second apart
 * are recorded as simultaneous, and "what happened first" — the question an audit log exists to
 * answer — becomes unanswerable exactly when several things happened at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE audit_logs ALTER COLUMN created_at TYPE timestamp(6) without time zone');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_logs ALTER COLUMN created_at TYPE timestamp(0) without time zone');
    }
};
