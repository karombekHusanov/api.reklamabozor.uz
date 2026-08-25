<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redirect-flow (OAuth2 authorization-code) support for MyID. `state` is the
 * CSRF token we mint per authorization request and match on the public callback
 * to resolve which user started it; single-use, short-lived (MyID gives ~300s).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            $table->string('state', 64)->nullable()->after('session_id');
            $table->timestamp('state_expires_at')->nullable()->after('state');

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            $table->dropIndex(['state']);
            $table->dropColumn(['state', 'state_expires_at']);
        });
    }
};
