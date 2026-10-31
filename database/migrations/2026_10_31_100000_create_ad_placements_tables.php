<?php

use App\Models\AdPlacement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advertising inventory on the map (LED screens first): the placements, their
 * rate cards (filled in Phase 1), and the screens a client picked for an order.
 * Additive — nothing existing is touched. The "LED ekran" category the orders
 * land in is created by `placements:import` / CategorySeeder / on first order
 * ({@see AdPlacement::defaultCategory()}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_placements', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20)->default('led_screen');
            $table->string('status', 20)->default('unclaimed');
            // Set once an approved agency claims the screen (Phase 1).
            $table->foreignId('owner_agent_profile_id')->nullable()
                ->constrained('agent_profiles')->nullOnDelete();
            // Owner as imported — shown until a claim replaces it.
            $table->string('owner_name_raw', 200);
            $table->string('owner_kind', 20)->nullable(); // legal | individual | brand
            $table->string('external_ref', 60)->nullable(); // owner's own numbering
            // "owner|ref" — makes a re-import idempotent.
            $table->string('import_key', 260)->nullable()->unique();
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->string('address', 300);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            // Screens on the same pole / two faces of one crossroads.
            $table->string('group_key', 40)->nullable();
            $table->json('photo_file_ids')->nullable();
            // width_m, height_m, resolution, sides, hours_from, hours_to …
            $table->json('specs')->nullable();
            $table->unsignedSmallInteger('slot_seconds')->nullable();
            $table->unsignedSmallInteger('shows_per_hour')->nullable();
            // center, transport_hub, students, retail, business_center, residential …
            $table->json('audience_tags')->nullable();
            $table->string('source', 20)->default('import'); // import | owner | admin
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('region_id');
            $table->index(['lat', 'lng']);
            $table->index('group_key');
        });

        Schema::create('ad_placement_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_placement_id')->constrained('ad_placements')->cascadeOnDelete();
            $table->string('unit', 10)->default('day'); // day | week | month
            $table->unsignedSmallInteger('slot_seconds')->default(15);
            $table->unsignedSmallInteger('shows_per_hour')->default(6);
            $table->unsignedBigInteger('price_tiyin');
            $table->unsignedInteger('min_quantity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['ad_placement_id', 'is_active']);
        });

        Schema::create('order_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('ad_placement_id')->constrained('ad_placements')->restrictOnDelete();
            // Frozen at order time so the order reads the same after edits.
            $table->string('address_snapshot', 300);
            $table->string('owner_name_snapshot', 200);
            $table->foreignId('rate_id')->nullable()->constrained('ad_placement_rates')->nullOnDelete();
            $table->string('unit', 10)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedSmallInteger('slot_seconds')->nullable();
            $table->unsignedSmallInteger('shows_per_hour')->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->unsignedBigInteger('estimated_price_tiyin')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['order_id', 'ad_placement_id']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('order_placements');
        Schema::dropIfExists('ad_placement_rates');
        Schema::dropIfExists('ad_placements');
    }
};
