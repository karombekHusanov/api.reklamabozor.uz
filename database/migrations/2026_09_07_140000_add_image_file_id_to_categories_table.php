<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Categories gain an optional illustration, managed from the admin panel and
     * shown by the mini app in place of the generated icon.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreignId('image_file_id')
                ->nullable()
                ->after('type')
                ->constrained('files')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('image_file_id');
        });
    }
};
