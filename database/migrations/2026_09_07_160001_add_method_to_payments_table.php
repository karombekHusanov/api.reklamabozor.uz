<?php

use App\Enums\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments gain a method: the Multicard gateway (checkout / invoice link / QR)
 * or an offline route (cash, bank transfer) a manager confirms by hand. Offline
 * rows carry the confirmation trail; gateway rows carry the shareable invoice
 * link used for QR codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('method', 16)->default(PaymentMethod::Multicard->value)->index();
            // Multicard's short checkout link (production only) — QR source.
            $table->string('short_link')->nullable();
            // Generated invoice / payment slip handed to the client.
            $table->foreignId('invoice_file_id')->nullable()->constrained('files')->nullOnDelete();
            // Offline confirmation trail.
            $table->string('reference', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_file_id');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['method', 'short_link', 'reference', 'note', 'confirmed_at']);
        });
    }
};
