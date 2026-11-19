<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConstraintTest extends TestCase
{
    use RefreshDatabase;

    private function createKoperasi(string $slug): int
    {
        return DB::table('koperasis')->insertGetId([
            'name' => 'Koperasi '.$slug,
            'slug' => $slug,
            'address' => 'Jl. Satuan No. 1',
            'phone' => '081200000001',
            'status' => 'active',
            'open_time' => '07:00:00',
            'close_time' => '17:00:00',
            'bank_name' => 'BRI',
            'bank_account_number' => '1234567890',
            'gateway_merchant_id' => 'enc-merchant',
            'gateway_credentials' => 'enc-creds',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_payments_xor_rejects_both_order_and_sale_null(): void
    {
        $koperasiId = $this->createKoperasi('kop-xor-1');

        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            'koperasi_id' => $koperasiId,
            'order_id' => null,
            'sale_id' => null,
            'payment_reference' => 'PAY-XOR-NULL',
            'amount' => 10000,
            'status' => 'created',
            'expired_at' => now()->addMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_payments_xor_rejects_both_order_and_sale_filled(): void
    {
        $koperasiId = $this->createKoperasi('kop-xor-2');
        $userId = DB::table('users')->insertGetId([
            'koperasi_id' => $koperasiId,
            'name' => 'Kasir Uji',
            'email' => 'kasir-xor@koperasi.test',
            'password' => bcrypt('password'),
            'role' => 'kasir',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Anggota Uji',
            'email' => 'anggota-xor@koperasi.test',
            'phone' => '081234567890',
            'password' => bcrypt('password'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'koperasi_id' => $koperasiId,
            'customer_id' => $customerId,
            'order_number' => 'ORD-XOR-1',
            'status' => 'pending_payment',
            'total_amount' => 25000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shiftId = DB::table('cashier_shifts')->insertGetId([
            'koperasi_id' => $koperasiId,
            'user_id' => $userId,
            'start_time' => now(),
            'opening_balance' => 100000,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $saleId = DB::table('sales')->insertGetId([
            'koperasi_id' => $koperasiId,
            'shift_id' => $shiftId,
            'user_id' => $userId,
            'invoice_number' => 'INV-XOR-1',
            'total_amount' => 25000,
            'payment_method' => 'qris',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            'koperasi_id' => $koperasiId,
            'order_id' => $orderId,
            'sale_id' => $saleId,
            'payment_reference' => 'PAY-XOR-BOTH',
            'amount' => 25000,
            'status' => 'created',
            'expired_at' => now()->addMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_product_barcode_is_unique_per_koperasi_only(): void
    {
        $kopA = $this->createKoperasi('kop-a');
        $kopB = $this->createKoperasi('kop-b');

        $catId = DB::table('categories')->insertGetId([
            'koperasi_id' => null,
            'name' => 'Beras',
            'slug' => 'beras',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = DB::table('units')->insertGetId([
            'name' => 'kg',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $insertProduct = fn (int $kopId, string $slug, string $barcode) => DB::table('products')->insert([
            'koperasi_id' => $kopId,
            'category_id' => $catId,
            'unit_id' => $unitId,
            'name' => 'Beras 5kg',
            'slug' => $slug,
            'barcode' => $barcode,
            'price' => 75000,
            'cost_price' => 68000,
            'stock' => 20,
            'is_available' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Koperasi A dan B boleh memiliki barcode yang sama
        $insertProduct($kopA, 'beras-5kg', '8991234567890');
        $insertProduct($kopB, 'beras-5kg', '8991234567890');

        $this->assertSame(2, DB::table('products')->count());

        // Koperasi A tidak boleh memiliki barcode duplikat
        $this->expectException(QueryException::class);
        $insertProduct($kopA, 'beras-5kg-2', '8991234567890');
    }

    public function test_report_snapshots_is_unique_per_koperasi_and_date(): void
    {
        $kopId = $this->createKoperasi('kop-snap');

        DB::table('report_snapshots')->insert([
            'koperasi_id' => $kopId,
            'date' => '2026-11-20',
            'total_sales' => 500000,
            'total_transactions' => 10,
            'total_online_orders' => 4,
            'total_pos_orders' => 6,
            'total_active_customers' => 3,
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('report_snapshots')->insert([
            'koperasi_id' => $kopId,
            'date' => '2026-11-20',
            'total_sales' => 600000,
            'created_at' => now(),
        ]);
    }
}
