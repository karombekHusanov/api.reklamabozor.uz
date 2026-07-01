<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // How soon the client needs the work done (today_tomorrow | this_week).
            $table->string('deadline', 20)->nullable()->after('description');
            // Extra reference files (slots 2-4); the primary brief stays in tz_file_id.
            $table->json('attachment_file_ids')->nullable()->after('tz_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['deadline', 'attachment_file_ids']);
        });
    }
};
