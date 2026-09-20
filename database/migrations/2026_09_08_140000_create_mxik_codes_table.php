<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue of MXIK (IKPU) classifier codes used on fiscal receipts.
 *
 * A fiscal OFD receipt line needs `mxik` + `package_code` + a VAT rate per
 * item, and a partial refund is refused without them. Codes are entered by an
 * operator from the official classifier — never invented — and reused across
 * pricelists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mxik_codes', function (Blueprint $table): void {
            $table->id();
            // 17-digit classifier code, e.g. 11307014001000000.
            $table->string('code', 32)->unique();
            // Packaging code that belongs to this MXIK (OFD requires it).
            $table->string('package_code', 32)->nullable();
            $table->string('name_uz');
            $table->string('name_ru')->nullable();
            // Percent, e.g. 12.00; 0 = no VAT.
            $table->decimal('vat_rate', 5, 2)->default(0);
            // Unit label shown to the operator when picking a code.
            $table->string('unit', 32)->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // A category's default code — kept out of `categories` so the catalogue
        // owns the mapping (one default per category).
        Schema::create('category_mxik_defaults', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('mxik_code_id')->constrained('mxik_codes')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_mxik_defaults');
        Schema::dropIfExists('mxik_codes');
    }
};
