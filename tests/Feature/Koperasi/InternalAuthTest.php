<?php

namespace Tests\Feature\Koperasi;

use App\Http\Middleware\EnsureMfaEnabled;
use App\Models\Koperasi;
use App\Models\User;
use App\Support\Auth\LoginLockout;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InternalAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        RateLimiter::clear(md5('login127.0.0.1'));
    }

    private function attemptLogin(string $email, string $password): TestResponse
    {
        return $this->post(route('login.store'), [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function test_wrong_password_and_unknown_email_return_same_generic_message(): void
    {
        $this->attemptLogin('admin.yonif501@koperasi.test', 'salah-sandi')
            ->assertSessionHasErrors(['email' => 'Surel atau kata sandi tidak sesuai.']);

        $this->attemptLogin('tidak-ada@koperasi.test', 'apa-saja')
            ->assertSessionHasErrors(['email' => 'Surel atau kata sandi tidak sesuai.']);

        $this->assertGuest('web');
    }

    public function test_five_failures_lock_account_for_fifteen_minutes(): void
    {
        $email = 'kasir.yonif501@koperasi.test';

        foreach (range(1, LoginLockout::MAX_FAILURES) as $ignored) {
            $this->attemptLogin($email, 'salah-sandi');
        }

        $this->attemptLogin($email, 'password')->assertSessionHasErrors('email');
        $this->assertStringContainsString('Akun terkunci sementara', (string) session('errors')->first('email'));
        $this->assertGuest('web');

        $this->travel(16)->minutes();

        $this->attemptLogin($email, 'password')->assertSessionHasNoErrors();
        $this->assertAuthenticated('web');
    }

    public function test_inactive_user_and_inactive_koperasi_are_rejected_at_login(): void
    {
        $kasir = User::query()->where('email', 'kasir.yonif501@koperasi.test')->firstOrFail();
        $kasir->forceFill(['is_active' => false])->save();

        $this->attemptLogin($kasir->email, 'password')
            ->assertSessionHasErrors(['email' => 'Surel atau kata sandi tidak sesuai.']);

        $kopB = Koperasi::query()->where('slug', 'primkopal-lantamal-v')->firstOrFail();
        $kopB->update(['status' => 'inactive']);

        $this->attemptLogin('admin.lantamal5@koperasi.test', 'password')
            ->assertSessionHasErrors(['email' => 'Surel atau kata sandi tidak sesuai.']);
    }

    public function test_dashboard_redirects_according_to_role(): void
    {
        $superAdmin = User::query()->where('email', 'pusat@koperasi.test')->firstOrFail();
        $this->actingAs($superAdmin, 'web')
            ->get(route('dashboard'))
            ->assertRedirect(route('pusat.dashboard'));

        $adminKoperasi = User::query()->where('email', 'admin.yonif501@koperasi.test')->firstOrFail();
        $this->actingAs($adminKoperasi, 'web')
            ->get(route('dashboard'))
            ->assertRedirect(route('koperasi.dashboard', ['koperasi' => 'primkopad-yonif-501']));
    }

    public function test_mfa_is_enforced_for_super_admin_and_admin_koperasi_but_not_kasir(): void
    {
        config(['koperasi.enforce_mfa' => true]);

        $superAdmin = User::query()->where('email', 'pusat@koperasi.test')->firstOrFail();
        $adminA = User::query()->where('email', 'admin.yonif501@koperasi.test')->firstOrFail();
        $kasirA = User::query()->where('email', 'kasir.yonif501@koperasi.test')->firstOrFail();

        $setupRoute = EnsureMfaEnabled::setupRouteName();

        // Super Admin & Admin Koperasi tanpa MFA ditolak atau diarahkan ke pengaturan 2FA
        $superAdminResponse = $this->actingAs($superAdmin, 'web')->get(route('pusat.dashboard'));
        $adminResponse = $this->actingAs($adminA, 'web')->get(route('koperasi.dashboard', ['koperasi' => 'primkopad-yonif-501']));

        if ($setupRoute !== null) {
            $superAdminResponse->assertRedirect(route($setupRoute));
            $adminResponse->assertRedirect(route($setupRoute));
        } else {
            $superAdminResponse->assertForbidden();
            $adminResponse->assertForbidden();
        }

        // Kasir tetap boleh masuk tanpa MFA (SRS v3.2 FR-KOP-01)
        $this->actingAs($kasirA, 'web')
            ->get(route('koperasi.dashboard', ['koperasi' => 'primkopad-yonif-501']))
            ->assertOk();

        // Setelah MFA terkonfirmasi, Admin Koperasi boleh masuk dashboard
        $adminA->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->actingAs($adminA->fresh(), 'web')
            ->get(route('koperasi.dashboard', ['koperasi' => 'primkopad-yonif-501']))
            ->assertOk();
    }
}
