<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('koperasi.super_admin.email');
        $name = (string) config('koperasi.super_admin.name');
        $password = (string) config('koperasi.super_admin.password');

        $user = User::withTrashed()->firstOrNew(['email' => $email]);

        $user->forceFill([
            'koperasi_id' => null,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'super_admin',
            'is_active' => true,
            'email_verified_at' => now(),
            'deleted_at' => null,
        ])->save();
    }
}
