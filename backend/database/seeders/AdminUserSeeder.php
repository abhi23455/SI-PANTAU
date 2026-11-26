<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SIPANTAU_ADMIN_EMAIL');
        $password = env('SIPANTAU_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            throw new RuntimeException('Atur SIPANTAU_ADMIN_EMAIL dan SIPANTAU_ADMIN_PASSWORD di .env sebelum membuat admin.');
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('SIPANTAU_ADMIN_NAME', 'Administrator SIPANTAU'),
                'password' => $password,
                'role' => 'admin',
                'status' => 'active',
            ],
        );
    }
}
