<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_portfolio_item_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_item_id')->constrained('agent_portfolio_items')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['portfolio_item_id', 'file_id']);
        });

        Schema::create('agent_portfolio_item_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_item_id')->constrained('agent_portfolio_items')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['portfolio_item_id', 'file_id']);
        });

        // Backfill the cover image into the gallery pivot for existing rows.
        DB::table('agent_portfolio_items')
            ->select(['id', 'image_file_id'])
            ->orderBy('id')
            ->each(function (object $row): void {
                DB::table('agent_portfolio_item_images')->insert([
                    'portfolio_item_id' => $row->id,
                    'file_id' => $row->image_file_id,
                    'sort_order' => 0,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_portfolio_item_attachments');
        Schema::dropIfExists('agent_portfolio_item_images');
    }
};
