<?php

namespace Tests\Feature\Koperasi;

use App\Models\Category;
use App\Models\Koperasi;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProductManagerTest extends TestCase
{
    use RefreshDatabase;

    private Koperasi $kopA;

    private User $adminA;

    private User $kasirA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $this->adminA = User::query()->where('email', 'admin.yonif501@koperasi.test')->firstOrFail();
        $this->kasirA = User::query()->where('email', 'kasir.yonif501@koperasi.test')->firstOrFail();
    }

    public function test_admin_and_kasir_can_open_products_page(): void
    {
        $this->actingAs($this->adminA, 'web')
            ->get(route('koperasi.products.index', ['koperasi' => $this->kopA->slug]))
            ->assertOk()
            ->assertSeeLivewire('catalog::product-manager')
            ->assertSee('Tambah Produk Baru');

        $this->actingAs($this->kasirA, 'web')
            ->get(route('koperasi.products.index', ['koperasi' => $this->kopA->slug]))
            ->assertOk()
            ->assertSeeLivewire('catalog::product-manager')
            ->assertDontSee('Tambah Produk Baru');
    }

    public function test_admin_creates_custom_category_and_product_with_webp_photo(): void
    {
        Storage::fake('public');

        $unit = Unit::query()->where('name', 'pcs')->firstOrFail();

        Livewire::actingAs($this->adminA, 'web')
            ->test('catalog::product-manager', ['koperasiId' => $this->kopA->id])
            ->set('newCategoryName', 'Perlengkapan Dinas')
            ->call('addCategory')
            ->assertHasNoErrors();

        $customCategory = Category::query()
            ->where('koperasi_id', $this->kopA->id)
            ->where('name', 'Perlengkapan Dinas')
            ->firstOrFail();

        Livewire::actingAs($this->adminA, 'web')
            ->test('catalog::product-manager', ['koperasiId' => $this->kopA->id])
            ->set('name', 'Kaos Kaki PDL')
            ->set('description', 'Kaos kaki tebal standar dinas')
            ->set('categoryId', $customCategory->id)
            ->set('unitId', $unit->id)
            ->set('barcode', '8990001999999')
            ->set('price', 25000)
            ->set('costPrice', 18000)
            ->set('stock', 30)
            ->set('photo', UploadedFile::fake()->image('kaoskaki.jpg', 1200, 900))
            ->call('saveProduct')
            ->assertHasNoErrors()
            ->assertSee('Kaos Kaki PDL');

        $product = Product::query()->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $this->kopA->id)
            ->where('barcode', '8990001999999')
            ->firstOrFail();

        $this->assertStringEndsWith('.webp', (string) $product->image_path);
        Storage::disk('public')->assertExists((string) $product->image_path);

        // Duplikat barcode di koperasi yang sama ditolak
        Livewire::actingAs($this->adminA, 'web')
            ->test('catalog::product-manager', ['koperasiId' => $this->kopA->id])
            ->set('name', 'Produk Barcode Sama')
            ->set('categoryId', $customCategory->id)
            ->set('unitId', $unit->id)
            ->set('barcode', '8990001999999')
            ->set('price', 10000)
            ->set('costPrice', 8000)
            ->set('stock', 5)
            ->call('saveProduct')
            ->assertHasErrors(['barcode' => 'unique']);
    }

    public function test_admin_can_adjust_stock_via_ui(): void
    {
        $product = Product::query()->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $this->kopA->id)
            ->firstOrFail();
        $initial = (int) $product->stock;

        Livewire::actingAs($this->adminA, 'web')
            ->test('catalog::product-manager', ['koperasiId' => $this->kopA->id])
            ->call('startAdjustment', $product->id)
            ->set('adjustmentType', 'in')
            ->set('adjustmentQty', 12)
            ->set('adjustmentNotes', 'Kiriman stok pagi')
            ->call('submitAdjustment')
            ->assertHasNoErrors();

        $this->assertSame($initial + 12, (int) $product->fresh()->stock);
    }

    public function test_kasir_can_toggle_availability_but_cannot_mutate_product_or_stock(): void
    {
        $product = Product::query()->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $this->kopA->id)
            ->firstOrFail();

        Livewire::actingAs($this->kasirA, 'web')
            ->test('catalog::product-manager', ['koperasiId' => $this->kopA->id])
            ->call('toggleAvailability', $product->id)
            ->assertHasNoErrors();

        $this->assertFalse($product->fresh()->is_available);

        // Aksi manajemen tetap ditolak 403 di server bagi Kasir
        Livewire::actingAs($this->kasirA, 'web')
            ->test('catalog::product-manager', ['koperasiId' => $this->kopA->id])
            ->call('deleteProduct', $product->id)
            ->assertForbidden();
    }

    public function test_admin_cannot_access_other_koperasi_product_manager(): void
    {
        $kopB = Koperasi::query()->where('slug', 'primkopal-lantamal-v')->firstOrFail();

        Livewire::actingAs($this->adminA, 'web')
            ->test('catalog::product-manager', ['koperasiId' => $kopB->id])
            ->assertForbidden();
    }
}
