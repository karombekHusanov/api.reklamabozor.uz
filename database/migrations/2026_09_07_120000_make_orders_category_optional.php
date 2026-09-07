<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The MVP request form asks for a description and nothing else — category,
 * region, files and the map pin are all optional. A category-less order is a
 * broadcast: every approved provider sees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable(false)->change();
        });
    }
};
