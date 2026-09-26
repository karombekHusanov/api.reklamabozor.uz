<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 20);
            // Provider's card id + token (the token is stored encrypted). The
            // card number / expiry are never stored — only a masked PAN.
            $table->string('card_id', 64);
            $table->text('card_token');
            $table->string('pan_mask', 32);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'gateway', 'card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_cards');
    }
};
