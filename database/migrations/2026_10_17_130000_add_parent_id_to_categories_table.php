<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nested categories: a category may sit under another one to any depth
 * (Dizayn → Logotip → Minimalist).
 *
 * `parent_id` is the tree edge; `path` is the materialized ancestor chain
 * ("/1/5/12/", own id included) so a whole subtree is one prefix LIKE and an
 * ancestor list is a string split; `depth` = number of ancestors (root = 0).
 * Children inherit the root's `type`; `is_other` rows stay roots.
 *
 * Additive: every existing category becomes a root.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('categories')
                ->restrictOnDelete();
            $table->string('path', 2000)->nullable()->after('parent_id');
            $table->unsignedSmallInteger('depth')->default(0)->after('path');
        });

        // Every existing row is a root: path = "/{id}/".
        DB::table('categories')->whereNull('path')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('categories')
                    ->where('id', $row->id)
                    ->update(['path' => '/'.$row->id.'/', 'depth' => 0]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['path', 'depth']);
        });
    }
};
