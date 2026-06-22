<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@reklamabozori.uz'],
            [
                'first_name' => 'superadmin',
                'last_name' => null,
                'password' => 'password',
                'role' => Role::Admin,
                'is_active' => true,
            ],
        );
    }
}
