<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Koperasi;
use App\Models\Order;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Catalog\Services\PublicCatalogQuery;
use App\Policies\OrderPolicy;
use App\Policies\ProductPolicy;
use App\Services\Shared\AuditLogger;
use App\Support\Tenancy\KoperasiContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_global_scope_returns_only_active_koperasi_products(): void
    {
        $kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $kopB = Koperasi::query()->where('slug', 'primkopal-lantamal-v')->firstOrFail();

        app(KoperasiContext::class)->set($kopA);
        $this->assertSame(4, Product::query()->count());
        $this->assertTrue(Product::query()->get()->every(fn (Product $p): bool => (int) $p->koperasi_id === (int) $kopA->id));

        app(KoperasiContext::class)->set($kopB);
        $this->assertSame(2, Product::query()->count());
        $this->assertTrue(Product::query()->get()->every(fn (Product $p): bool => (int) $p->koperasi_id === (int) $kopB->id));
    }

    public function test_koperasi_id_is_autofilled_on_create_within_context(): void
    {
        $kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $category = Category::query()->whereNull('koperasi_id')->firstOrFail();
        $unit = Unit::query()->firstOrFail();

        app(KoperasiContext::class)->set($kopA);

        $product = Product::create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Kopi Kapal Api',
            'slug' => 'kopi-kapal-api',
            'price' => 15000,
            'cost_price' => 12000,
            'stock' => 10,
            'is_available' => true,
        ]);

        $this->assertSame((int) $kopA->id, (int) $product->koperasi_id);
    }

    public function test_koperasi_context_is_reset_between_requests(): void
    {
        $kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();

        app(KoperasiContext::class)->set($kopA);
        $this->assertTrue(app(KoperasiContext::class)->has());

        $this->app->forgetScopedInstances();
        $this->assertFalse(app(KoperasiContext::class)->has());
    }

    public function test_portal_routes_enforce_role_and_cross_koperasi_isolation(): void
    {
        $kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $kopB = Koperasi::query()->where('slug', 'primkopal-lantamal-v')->firstOrFail();

        $adminA = User::query()->where('email', 'admin.yonif501@koperasi.test')->firstOrFail();
        $superAdmin = User::query()->where('email', 'pusat@koperasi.test')->firstOrFail();

        // Guest diarahkan ke login untuk portal internal, tetapi bebas membuka landing page
        $this->get(route('customer.landing'))->assertOk();
        $this->get(route('koperasi.dashboard', ['koperasi' => $kopA->slug]))->assertRedirect(route('login'));
        $this->get(route('pusat.dashboard'))->assertRedirect(route('login'));

        // Admin Koperasi A boleh akses Koperasi A, ditolak (403) pada Koperasi B & Pusat
        $this->actingAs($adminA)
            ->get(route('koperasi.dashboard', ['koperasi' => $kopA->slug]))
            ->assertOk()
            ->assertSee($kopA->name);

        $this->actingAs($adminA)
            ->get(route('koperasi.dashboard', ['koperasi' => $kopB->slug]))
            ->assertForbidden();

        $this->actingAs($adminA)
            ->get(route('pusat.dashboard'))
            ->assertForbidden();

        // Super Admin Pusat boleh akses Pusat, ditolak (403) pada rute operasional Koperasi
        $this->actingAs($superAdmin)
            ->get(route('pusat.dashboard'))
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get(route('koperasi.dashboard', ['koperasi' => $kopA->slug]))
            ->assertForbidden();
    }

    public function test_customer_guard_is_isolated_from_internal_web_guard(): void
    {
        $kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $customer = Customer::query()->where('email', 'budi@anggota.test')->firstOrFail();

        // Customer yang login pada guard 'customer' tetap dianggap guest pada guard internal 'web'
        $this->actingAs($customer, 'customer')
            ->get(route('koperasi.dashboard', ['koperasi' => $kopA->slug]))
            ->assertRedirect(route('login'));

        $this->actingAs($customer, 'customer')
            ->get(route('pusat.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_inactive_koperasi_blocks_internal_access_and_public_catalog(): void
    {
        $kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $adminA = User::query()->where('email', 'admin.yonif501@koperasi.test')->firstOrFail();

        $kopA->update(['status' => 'inactive']);

        $this->actingAs($adminA)
            ->get(route('koperasi.dashboard', ['koperasi' => $kopA->slug]))
            ->assertForbidden();

        $catalog = app(PublicCatalogQuery::class)->forKoperasi($kopA->fresh());
        $this->assertCount(0, $catalog);
    }

    public function test_policies_deny_cross_koperasi_and_cross_customer_access(): void
    {
        $kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $kopB = Koperasi::query()->where('slug', 'primkopal-lantamal-v')->firstOrFail();

        $adminA = User::query()->where('email', 'admin.yonif501@koperasi.test')->firstOrFail();
        $productB = Product::query()->withoutGlobalScope('koperasi')->where('koperasi_id', $kopB->id)->firstOrFail();

        app(KoperasiContext::class)->set($kopA);
        $this->assertFalse((new ProductPolicy)->update($adminA, $productB));

        // Pelanggan lain tidak boleh melihat order milik customer A
        $customerA = Customer::query()->where('email', 'budi@anggota.test')->firstOrFail();
        $customerB = new Customer;
        $customerB->forceFill([
            'name' => 'Pratu Andi',
            'email' => 'andi@anggota.test',
            'phone' => '081299998888',
            'password' => bcrypt('password'),
            'is_active' => true,
        ])->save();

        $orderA = new Order;
        $orderA->forceFill([
            'koperasi_id' => $kopA->id,
            'customer_id' => $customerA->id,
            'order_number' => 'ORD-ISO-001',
            'status' => 'pending_payment',
            'total_amount' => 75000,
        ])->save();

        $this->assertTrue((new OrderPolicy)->view($customerA, $orderA));
        $this->assertFalse((new OrderPolicy)->view($customerB, $orderA));
    }

    public function test_public_catalog_query_never_exposes_cost_price_or_other_koperasi(): void
    {
        $kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $products = app(PublicCatalogQuery::class)->forKoperasi($kopA);

        $this->assertCount(4, $products);
        $this->assertTrue($products->every(fn (Product $p): bool => (int) $p->koperasi_id === (int) $kopA->id));
        $this->assertArrayNotHasKey('cost_price', $products->first()->toArray());
    }

    public function test_audit_logger_sanitizes_sensitive_keys(): void
    {
        $kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();

        $log = app(AuditLogger::class)->record(
            action: 'koperasi_updated',
            modelType: Koperasi::class,
            modelId: $kopA->id,
            oldValues: ['name' => 'Lama', 'gateway_credentials' => ['secret' => 'rahasia']],
            newValues: ['name' => 'Baru', 'password' => 'plain', 'bank_account_number' => '12345'],
            koperasiId: $kopA->id,
            actorType: 'system',
        );

        $stored = AuditLog::query()->findOrFail($log->id);
        $this->assertSame('system', $stored->actor_type);
        $this->assertNull($stored->user_id);
        $this->assertArrayNotHasKey('gateway_credentials', $stored->old_values);
        $this->assertArrayNotHasKey('password', $stored->new_values);
        $this->assertArrayNotHasKey('bank_account_number', $stored->new_values);
        $this->assertSame('Baru', $stored->new_values['name']);
    }
}
