<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An applied addendum can lower the deal price. When the client already paid,
 * the difference has to travel back — and Multicard has no partial refund, so
 * the obligation lives here and a manager settles it by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_amendments', function (Blueprint $table): void {
            $table->decimal('refund_amount', 14, 2)->default(0)->after('extra_amount');
            // none | due | refunded | waived
            $table->string('refund_state', 16)->default('none')->after('refund_amount')->index();
            $table->string('refund_method', 16)->nullable()->after('refund_state');
            $table->string('refund_reference', 120)->nullable()->after('refund_method');
            $table->string('refund_note', 500)->nullable()->after('refund_reference');
            $table->foreignId('refunded_by')->nullable()->after('refund_note')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable()->after('refunded_by');
        });
    }

    public function down(): void
    {
        Schema::table('order_amendments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn([
                'refund_amount', 'refund_state', 'refund_method',
                'refund_reference', 'refund_note', 'refunded_at',
            ]);
        });
    }
};
