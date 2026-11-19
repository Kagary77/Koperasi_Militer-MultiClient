<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\GlobalSetting;
use App\Models\Koperasi;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_is_idempotent_and_encrypts_gateway_credentials(): void
    {
        // Jalankan seeder 2 kali berturut-turut untuk membuktikan tidak ada duplikasi
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, GlobalSetting::query()->count());
        $this->assertSame(5, Unit::query()->count());
        $this->assertSame(8, Category::query()->whereNull('koperasi_id')->count());
        $this->assertSame(2, Koperasi::query()->count());
        $this->assertSame(1, User::query()->where('role', 'super_admin')->count());
        $this->assertSame(2, User::query()->where('role', 'admin_koperasi')->count());
        $this->assertSame(2, User::query()->where('role', 'kasir')->count());
        $this->assertSame(6, Product::query()->count());
        $this->assertSame(1, Customer::query()->count());

        // Pastikan kredensial gateway benar-benar terenkripsi di MariaDB dan dapat didekripsi oleh Model
        $koperasi = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $raw = DB::table('koperasis')->where('id', $koperasi->id)->first();

        $this->assertStringNotContainsString('MRC-YONIF-501', (string) $raw->gateway_merchant_id);
        $this->assertStringNotContainsString('whsec-yonif-501-demo', (string) $raw->gateway_credentials);
        $this->assertSame('MRC-YONIF-501', $koperasi->gateway_merchant_id);
        $this->assertSame('whsec-yonif-501-demo', $koperasi->gateway_credentials['webhook_secret']);
    }
}
