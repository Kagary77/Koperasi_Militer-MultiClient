<?php

namespace Tests\Feature\Koperasi;

use App\Models\AuditLog;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Koperasi;
use App\Models\Product;
use App\Models\StockMutation;
use App\Models\User;
use App\Modules\Catalog\Events\CatalogChanged;
use App\Modules\Catalog\Services\ProductPhotoStore;
use App\Modules\Catalog\Services\ProductStockService;
use App\Modules\Payments\Jobs\SendWhatsAppNotificationJob;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductStockServiceTest extends TestCase
{
    use RefreshDatabase;

    private Koperasi $kopA;

    private User $adminA;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $this->adminA = User::query()->where('email', 'admin.yonif501@koperasi.test')->firstOrFail();
        $this->product = Product::query()->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $this->kopA->id)
            ->firstOrFail();
        $this->customer = Customer::query()->where('email', 'budi@anggota.test')->firstOrFail();

        $this->actingAs($this->adminA, 'web');
    }

    private function addProductToCustomerCart(int $qty = 2): CartItem
    {
        $item = new CartItem;
        $item->forceFill([
            'koperasi_id' => $this->kopA->id,
            'customer_id' => $this->customer->id,
            'product_id' => $this->product->id,
            'quantity' => $qty,
        ])->save();

        return $item;
    }

    public function test_stock_adjustment_in_and_out_records_mutation_audit_and_broadcast(): void
    {
        Event::fake([CatalogChanged::class]);
        $initialStock = (int) $this->product->stock;

        $service = app(ProductStockService::class);
        $service->adjustStock($this->product, 'in', 15, 'Restock gudang');
        $service->adjustStock($this->product->fresh(), 'damaged', 5, 'Kemasan bocor');

        $this->assertSame($initialStock + 10, (int) $this->product->fresh()->stock);

        $this->assertSame(2, StockMutation::query()->withoutGlobalScope('koperasi')->where('product_id', $this->product->id)->count());
        $this->assertSame(15, (int) StockMutation::query()->withoutGlobalScope('koperasi')->where('type', 'in')->value('quantity'));
        $this->assertSame(-5, (int) StockMutation::query()->withoutGlobalScope('koperasi')->where('type', 'adjustment')->value('quantity'));
        $this->assertSame(2, AuditLog::query()->where('action', 'stock_adjusted')->count());

        Event::assertDispatchedTimes(CatalogChanged::class, 2);
    }

    public function test_stock_adjustment_rejects_negative_stock(): void
    {
        $currentStock = (int) $this->product->stock;

        $this->expectException(DomainException::class);

        app(ProductStockService::class)->adjustStock($this->product, 'out', $currentStock + 1, 'Melebihi stok');
    }

    public function test_stock_becoming_zero_invalidates_cart_and_notifies_customer(): void
    {
        Queue::fake();
        Event::fake([CatalogChanged::class]);

        $this->addProductToCustomerCart();
        $currentStock = (int) $this->product->stock;

        app(ProductStockService::class)->adjustStock($this->product, 'out', $currentStock, 'Stok habis');

        $this->assertSame(0, (int) $this->product->fresh()->stock);
        $this->assertSame(0, CartItem::query()->withoutGlobalScope('koperasi')->count());
        $this->assertSame(1, $this->customer->notifications()->count());

        Queue::assertPushed(SendWhatsAppNotificationJob::class, function (SendWhatsAppNotificationJob $job): bool {
            return $job->phone === $this->customer->phone && $job->context === 'cart_invalidation';
        });

        Event::assertDispatched(CatalogChanged::class);
    }

    public function test_toggle_availability_off_invalidates_cart_and_broadcasts(): void
    {
        Queue::fake();
        Event::fake([CatalogChanged::class]);

        $this->addProductToCustomerCart();

        app(ProductStockService::class)->toggleAvailability($this->product);

        $this->assertFalse($this->product->fresh()->is_available);
        $this->assertSame(0, CartItem::query()->withoutGlobalScope('koperasi')->count());
        $this->assertSame(1, $this->customer->notifications()->count());

        Queue::assertPushed(SendWhatsAppNotificationJob::class);
        Event::assertDispatched(CatalogChanged::class, function (CatalogChanged $event): bool {
            return $event->koperasiId === (int) $this->kopA->id
                && $event->productId === (int) $this->product->id
                && $event->broadcastOn()[0]->name === 'koperasi.'.$this->kopA->id.'.catalog';
        });
    }

    public function test_soft_delete_product_keeps_record_and_invalidates_cart(): void
    {
        Queue::fake();
        Event::fake([CatalogChanged::class]);

        $this->addProductToCustomerCart();

        app(ProductStockService::class)->softDelete($this->product);

        $this->assertSoftDeleted('products', ['id' => $this->product->id]);
        $this->assertSame(0, CartItem::query()->withoutGlobalScope('koperasi')->count());
        $this->assertSame(1, $this->customer->notifications()->count());
        Queue::assertPushed(SendWhatsAppNotificationJob::class);
        Event::assertDispatched(CatalogChanged::class);
    }

    public function test_product_photo_is_compressed_to_webp_max_800px(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('beras.jpg', 1600, 1200);
        $path = app(ProductPhotoStore::class)->store($file, (int) $this->kopA->id);

        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertExists($path);

        $fullPath = Storage::disk('public')->path($path);
        $this->assertSame('image/webp', mime_content_type($fullPath));
        [$width] = getimagesize($fullPath);
        $this->assertSame(800, $width);
    }
}
