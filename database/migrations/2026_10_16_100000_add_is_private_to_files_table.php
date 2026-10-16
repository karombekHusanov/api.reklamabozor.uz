<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KYC scans, contracts, invoices and acts move off the public disk to an
 * authenticated stream endpoint (GET /files/{file}); `is_private` marks which
 * rows that applies to. Additive — existing rows default to public.
 * Run `php artisan files:privatize` after deploying to move the old files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->boolean('is_private')->default(false)->after('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->dropColumn('is_private');
        });
    }
};
