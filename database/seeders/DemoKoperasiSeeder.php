<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Koperasi;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoKoperasiSeeder extends Seeder
{
    public function run(): void
    {
        $units = Unit::query()->pluck('id', 'name');
        $categories = Category::query()->whereNull('koperasi_id')->pluck('id', 'slug');

        $koperasis = [
            [
                'slug' => 'primkopad-yonif-501',
                'name' => 'Primkopad Yonif 501',
                'address' => 'Jl. Urip Sumoharjo No. 10, Madiun',
                'phone' => '081211110501',
                'open_time' => '07:00:00',
                'close_time' => '17:00:00',
                'bank_name' => 'BRI',
                'bank_account_number' => '005101000501301',
                'merchant_id' => 'MRC-YONIF-501',
                'webhook_secret' => 'whsec-yonif-501-demo',
                'users' => [
                    ['name' => 'Admin Yonif 501', 'email' => 'admin.yonif501@koperasi.test', 'role' => 'admin_koperasi'],
                    ['name' => 'Kasir Yonif 501', 'email' => 'kasir.yonif501@koperasi.test', 'role' => 'kasir'],
                ],
                'products' => [
                    [
                        'name' => 'Beras Premium 5kg',
                        'category' => 'beras-biji-bijian',
                        'unit' => 'kg',
                        'barcode' => '8990001000011',
                        'price' => 75000,
                        'cost_price' => 68000,
                        'stock' => 50,
                        'description' => 'Beras putih pulen kemasan 5 kg.',
                    ],
                    [
                        'name' => 'Minyak Goreng 2 Liter',
                        'category' => 'minyak-goreng',
                        'unit' => 'liter',
                        'barcode' => '8990001000028',
                        'price' => 36000,
                        'cost_price' => 32000,
                        'stock' => 40,
                        'description' => 'Minyak goreng kelapa sawit kemasan pouch 2L.',
                    ],
                    [
                        'name' => 'Gula Pasir 1kg',
                        'category' => 'gula-garam',
                        'unit' => 'kg',
                        'barcode' => '8990001000035',
                        'price' => 17500,
                        'cost_price' => 15500,
                        'stock' => 60,
                        'description' => 'Gula kristal putih 1 kg.',
                    ],
                    [
                        'name' => 'Mie Instan Goreng 1 Dus',
                        'category' => 'mie-makanan-instan',
                        'unit' => 'dus',
                        'barcode' => '8990001000042',
                        'price' => 115000,
                        'cost_price' => 105000,
                        'stock' => 25,
                        'description' => 'Isi 40 bungkus mie instan goreng.',
                    ],
                ],
            ],
            [
                'slug' => 'primkopal-lantamal-v',
                'name' => 'Primkopal Lantamal V',
                'address' => 'Jl. Laksda M. Nasir No. 56, Surabaya',
                'phone' => '081222220005',
                'open_time' => '07:30:00',
                'close_time' => '16:30:00',
                'bank_name' => 'Mandiri',
                'bank_account_number' => '1400005550051',
                'merchant_id' => 'MRC-LANTAMAL-V',
                'webhook_secret' => 'whsec-lantamal-v-demo',
                'users' => [
                    ['name' => 'Admin Lantamal V', 'email' => 'admin.lantamal5@koperasi.test', 'role' => 'admin_koperasi'],
                    ['name' => 'Kasir Lantamal V', 'email' => 'kasir.lantamal5@koperasi.test', 'role' => 'kasir'],
                ],
                'products' => [
                    [
                        'name' => 'Beras Medium 5kg',
                        'category' => 'beras-biji-bijian',
                        'unit' => 'kg',
                        'barcode' => '8990002000011',
                        'price' => 68000,
                        'cost_price' => 61000,
                        'stock' => 45,
                        'description' => 'Beras medium kualitas koperasi kemasan 5 kg.',
                    ],
                    [
                        'name' => 'Telur Ayam Negeri 1kg',
                        'category' => 'telur-protein',
                        'unit' => 'kg',
                        'barcode' => '8990002000028',
                        'price' => 29000,
                        'cost_price' => 26000,
                        'stock' => 30,
                        'description' => 'Telur ayam segar per kilogram.',
                    ],
                ],
            ],
        ];

        foreach ($koperasis as $spec) {
            $koperasi = Koperasi::withTrashed()->firstOrNew(['slug' => $spec['slug']]);
            $koperasi->forceFill([
                'name' => $spec['name'],
                'slug' => $spec['slug'],
                'address' => $spec['address'],
                'phone' => $spec['phone'],
                'status' => 'active',
                'open_time' => $spec['open_time'],
                'close_time' => $spec['close_time'],
                'bank_name' => $spec['bank_name'],
                'bank_account_number' => $spec['bank_account_number'],
                'gateway_merchant_id' => $spec['merchant_id'],
                'gateway_credentials' => [
                    'provider' => 'fake-qris-sandbox',
                    'server_key' => 'srv-'.$spec['slug'],
                    'webhook_secret' => $spec['webhook_secret'],
                ],
                'deleted_at' => null,
            ])->save();

            foreach ($spec['users'] as $u) {
                $user = User::withTrashed()->firstOrNew(['email' => $u['email']]);
                $user->forceFill([
                    'koperasi_id' => $koperasi->id,
                    'name' => $u['name'],
                    'email' => $u['email'],
                    'password' => Hash::make('password'),
                    'role' => $u['role'],
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'deleted_at' => null,
                ])->save();
            }

            foreach ($spec['products'] as $p) {
                $slug = Str::slug($p['name']);
                $product = Product::withTrashed()->firstOrNew([
                    'koperasi_id' => $koperasi->id,
                    'slug' => $slug,
                ]);
                $product->forceFill([
                    'koperasi_id' => $koperasi->id,
                    'category_id' => $categories[$p['category']],
                    'unit_id' => $units[$p['unit']],
                    'name' => $p['name'],
                    'slug' => $slug,
                    'description' => $p['description'],
                    'barcode' => $p['barcode'],
                    'price' => $p['price'],
                    'cost_price' => $p['cost_price'],
                    'stock' => $p['stock'],
                    'is_available' => true,
                    'deleted_at' => null,
                ])->save();
            }
        }

        // 1 akun pelanggan demo untuk pengujian lokal Rekan 2 (Pelanggan)
        $customer = Customer::withTrashed()->firstOrNew(['email' => 'budi@anggota.test']);
        $customer->forceFill([
            'name' => 'Serda Budi Santoso',
            'email' => 'budi@anggota.test',
            'phone' => '081234567890',
            'password' => Hash::make('password'),
            'is_active' => true,
            'is_verified' => true,
            'deleted_at' => null,
        ])->save();
    }
}
