<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->string('direction', 32)->default('client_to_provider')->after('order_id');
            $table->foreignId('reviewer_id')->nullable()->after('agent_profile_id')->constrained('users');
            $table->foreignId('reviewee_id')->nullable()->after('reviewer_id')->constrained('users');
            $table->json('criteria')->nullable()->after('reviewee_id');
        });

        // Change rating from unsigned tiny int to decimal(3,2).
        // SQLite doesn't support ALTER COLUMN TYPE, so we rebuild via a temp column.
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE reviews ALTER COLUMN rating TYPE decimal(3,2) USING rating::decimal(3,2)');
        } elseif ($driver === 'sqlite') {
            Schema::table('reviews', function (Blueprint $table): void {
                $table->decimal('rating_new', 3, 2)->default(0)->after('rating');
            });
            DB::table('reviews')->update(['rating_new' => DB::raw('CAST(rating AS REAL)')]);
            Schema::table('reviews', function (Blueprint $table): void {
                $table->dropColumn('rating');
            });
            Schema::table('reviews', function (Blueprint $table): void {
                $table->renameColumn('rating_new', 'rating');
            });
        }

        // Drop the old unique on order_id alone.
        Schema::table('reviews', function (Blueprint $table) use ($driver): void {
            if ($driver === 'sqlite') {
                // SQLite unique indexes are named differently; drop by index name.
                $indexes = collect(DB::select("PRAGMA index_list('reviews')"));
                $uniqueIdx = $indexes->firstWhere(fn ($idx) => $idx->unique && str_contains($idx->name, 'order_id'));

                if ($uniqueIdx) {
                    $table->dropIndex($uniqueIdx->name);
                }
            } else {
                $table->dropUnique(['order_id']);
            }
        });

        // Backfill existing rows.
        DB::table('reviews')->whereNull('reviewer_id')->update([
            'reviewer_id' => DB::raw('client_id'),
            'reviewee_id' => DB::raw('agent_id'),
        ]);

        Schema::table('reviews', function (Blueprint $table): void {
            $table->unique(['order_id', 'direction']);
            $table->index(['reviewee_id', 'status', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropIndex(['reviewee_id', 'status', 'direction']);
            $table->dropUnique(['order_id', 'direction']);
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE reviews ALTER COLUMN rating TYPE smallint USING rating::smallint');
        }

        Schema::table('reviews', function (Blueprint $table): void {
            $table->unique('order_id');

            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['reviewer_id']);
                $table->dropForeign(['reviewee_id']);
            }

            $table->dropColumn(['direction', 'reviewer_id', 'reviewee_id', 'criteria']);
        });
    }
};
