<?php

namespace Tests\Feature\Pelanggan;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Koperasi;
use App\Modules\Ordering\Notifications\OrderStatusChangedNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAuthAndLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_landing_page_lists_only_active_koperasi_and_supports_search(): void
    {
        $inactive = new Koperasi;
        $inactive->forceFill([
            'name' => 'Primkop Nonaktif',
            'slug' => 'primkop-nonaktif',
            'address' => 'Jl. Nonaktif No. 99',
            'phone' => '081200000000',
            'open_time' => '07:00:00',
            'close_time' => '21:00:00',
            'bank_name' => 'BRI',
            'bank_account_number' => '001122334455',
            'gateway_merchant_id' => 'MERCH-NONAKTIF-01',
            'gateway_credentials' => ['server_key' => 'dummy-server-key'],
            'status' => 'inactive',
        ])->save();

        $this->get(route('customer.landing'))
            ->assertOk()
            ->assertSee('Primkopad Yonif 501')
            ->assertDontSee('Primkop Nonaktif');

        $this->get(route('customer.landing', ['q' => 'Yonif 501']))
            ->assertOk()
            ->assertSee('Primkopad Yonif 501')
            ->assertDontSee('Primkopal Lantamal V');
    }

    public function test_customer_can_register_and_is_authenticated_on_customer_guard_only(): void
    {
        $response = $this->post(route('customer.register.store'), [
            'name' => 'Sertu Andi Wijaya',
            'email' => 'Andi.Wijaya@Anggota.Test',
            'phone' => '081399887766',
            'password' => 'Rahasia123!',
            'password_confirmation' => 'Rahasia123!',
        ]);

        $response->assertRedirect(route('customer.landing'));

        $this->assertAuthenticated('customer');
        $this->assertGuest('web');

        $this->assertDatabaseHas('customers', [
            'email' => 'andi.wijaya@anggota.test',
            'phone' => '081399887766',
        ]);
        $this->assertTrue(AuditLog::query()->where('action', 'customer_registered')->exists());
    }

    public function test_customer_can_login_and_logout_on_customer_guard(): void
    {
        $customer = Customer::query()->where('email', 'budi@anggota.test')->firstOrFail();

        $this->post(route('customer.login.store'), [
            'email' => $customer->email,
            'password' => 'salah-password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest('customer');

        $this->post(route('customer.login.store'), [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertRedirect(route('customer.landing'));

        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertGuest('web');

        $this->post(route('customer.logout'))
            ->assertRedirect(route('customer.landing'));
        $this->assertGuest('customer');
    }

    public function test_customer_can_view_and_mark_in_app_notifications_as_read(): void
    {
        $customer = Customer::query()->where('email', 'budi@anggota.test')->firstOrFail();
        $kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();

        $customer->notify(new OrderStatusChangedNotification(
            koperasiId: (int) $kopA->id,
            orderId: 99,
            orderNumber: 'ORD-DEMO-001',
            status: 'ready',
            message: 'Pesanan ORD-DEMO-001 sudah siap diambil di loket.',
        ));

        $this->actingAs($customer, 'customer')
            ->get(route('customer.notifications'))
            ->assertOk()
            ->assertSee('Pesanan ORD-DEMO-001 sudah siap diambil di loket.');

        $this->actingAs($customer, 'customer')
            ->post(route('customer.notifications.read-all'))
            ->assertRedirect();

        $this->assertSame(0, $customer->fresh()->unreadNotifications()->count());
    }
}
