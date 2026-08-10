<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->timestamp('price_updated_at')->nullable()->after('comment');
            $table->unsignedSmallInteger('price_edit_count')->default(0)->after('price_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn(['price_updated_at', 'price_edit_count']);
        });
    }
};
