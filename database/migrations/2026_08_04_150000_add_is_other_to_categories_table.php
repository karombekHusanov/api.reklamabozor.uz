<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_other')->default(false)->after('is_active');
        });

        $now = now();

        foreach (['agent', 'designer'] as $type) {
            $existing = DB::table('categories')
                ->where('name_uz', 'Boshqa')
                ->where('type', $type)
                ->first();

            if ($existing !== null) {
                DB::table('categories')
                    ->where('id', $existing->id)
                    ->update([
                        'name_ru' => 'Другое',
                        'is_other' => true,
                        'is_active' => true,
                        'sort_order' => 999,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            DB::table('categories')->insert([
                'name_uz' => 'Boshqa',
                'name_ru' => 'Другое',
                'type' => $type,
                'is_active' => true,
                'is_other' => true,
                'sort_order' => 999,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('categories')->where('is_other', true)->delete();

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_other');
        });
    }
};
