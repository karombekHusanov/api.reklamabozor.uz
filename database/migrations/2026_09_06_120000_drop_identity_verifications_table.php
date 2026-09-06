<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * MyID (biometric identity verification) was fully removed from the product.
 * Drop its table where it was already created; a no-op on environments that
 * never ran the original create migration (which has been deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('identity_verifications');
    }

    public function down(): void
    {
        // Intentionally irreversible — the feature and its model are gone.
    }
};
