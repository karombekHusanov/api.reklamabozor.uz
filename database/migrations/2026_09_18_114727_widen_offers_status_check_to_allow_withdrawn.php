<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `offers.status` was created as `$table->enum(...)` (0001_01_01_000008),
 * which Postgres backs with a CHECK constraint on the literal value list —
 * unlike the PHP-side OfferStatus enum, adding a new case there does nothing
 * to the database. SQLite (the test connection) has no such constraint,
 * which is why this only shows up against real Postgres. Widen — never
 * narrow — the constraint to also allow 'withdrawn'. No-op on SQLite/MySQL,
 * where `enum` isn't backed by a named CHECK constraint the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_status_check');
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_status_check CHECK (status IN ('pending', 'accepted', 'rejected', 'withdrawn'))");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_status_check');
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_status_check CHECK (status IN ('pending', 'accepted', 'rejected'))");
    }
};
