<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('tz_file_path');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Technical brief (TZ) now lives in the files registry.
            $table->foreignId('tz_file_id')
                ->nullable()
                ->after('description')
                ->constrained('files')
                ->nullOnDelete();

            // Widen to plain string so new statuses can be added at the app layer.
            $table->string('status', 32)->default('new')->change();
        });

        // Drop the leftover enum check constraint on Postgres (the column is now free-form).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tz_file_id');
            $table->string('tz_file_path')->nullable()->after('description');
        });
    }
};
