<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('tender_access_at')->nullable();
            $table->foreignId('tender_access_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('tender_access_note')->nullable();
            $table->timestamp('tender_access_revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tender_access_by');
            $table->dropColumn(['tender_access_at', 'tender_access_note', 'tender_access_revoked_at']);
        });
    }
};
