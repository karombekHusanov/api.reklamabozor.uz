<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-declared legal nature for client/designer users. `agent`/`seller` are
 * always legal entities and derive it from their role, so this column stays
 * null for them (and for users who haven't been asked yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('person_type')->nullable()->after('role');
            $table->timestamp('person_type_selected_at')->nullable()->after('person_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['person_type', 'person_type_selected_at']);
        });
    }
};
