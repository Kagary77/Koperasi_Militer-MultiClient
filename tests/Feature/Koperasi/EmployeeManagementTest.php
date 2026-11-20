<?php

namespace Tests\Feature\Koperasi;

use App\Models\AuditLog;
use App\Models\Koperasi;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
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

    public function test_admin_koperasi_can_open_employee_page_and_kasir_is_forbidden(): void
    {
        $this->actingAs($this->adminA, 'web')
            ->get(route('koperasi.employees.index', ['koperasi' => $this->kopA->slug]))
            ->assertOk()
            ->assertSeeLivewire('ordering::employee-manager');

        $this->actingAs($this->kasirA, 'web')
            ->get(route('koperasi.employees.index', ['koperasi' => $this->kopA->slug]))
            ->assertForbidden();
    }

    public function test_admin_creates_and_updates_employee_bound_to_own_koperasi_with_audit_log(): void
    {
        Livewire::actingAs($this->adminA, 'web')
            ->test('ordering::employee-manager', ['koperasiId' => $this->kopA->id])
            ->set('name', 'Kopda Riko')
            ->set('email', 'riko@koperasi.test')
            ->set('role', 'kasir')
            ->set('password', 'rahasia123')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Kopda Riko');

        $created = User::query()->where('email', 'riko@koperasi.test')->firstOrFail();
        $this->assertSame((int) $this->kopA->id, (int) $created->koperasi_id);
        $this->assertSame('kasir', $created->role);
        $this->assertTrue($created->is_active);

        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'employee_created')
                ->where('model_id', $created->id)
                ->where('koperasi_id', $this->kopA->id)
                ->exists(),
        );

        // Duplikat email ditolak (UC-22 alur 2a)
        Livewire::actingAs($this->adminA, 'web')
            ->test('ordering::employee-manager', ['koperasiId' => $this->kopA->id])
            ->set('name', 'Duplikat')
            ->set('email', 'riko@koperasi.test')
            ->set('role', 'kasir')
            ->set('password', 'rahasia123')
            ->call('save')
            ->assertHasErrors(['email' => 'unique']);
    }

    public function test_deactivating_employee_terminates_active_session_immediately(): void
    {
        // Kasir aktif bisa membuka dashboard
        $this->actingAs($this->kasirA, 'web')
            ->get(route('koperasi.dashboard', ['koperasi' => $this->kopA->slug]))
            ->assertOk();

        // Admin menonaktifkan Kasir (UC-22 alur 3a)
        Livewire::actingAs($this->adminA, 'web')
            ->test('ordering::employee-manager', ['koperasiId' => $this->kopA->id])
            ->call('toggleActive', $this->kasirA->id)
            ->assertHasNoErrors();

        $this->assertFalse($this->kasirA->fresh()->is_active);
        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'employee_deactivated')
                ->where('model_id', $this->kasirA->id)
                ->exists(),
        );

        // Permintaan berikutnya oleh Kasir yang sedang memiliki sesi langsung diakhiri dan dialihkan ke login
        $this->actingAs($this->kasirA->fresh(), 'web')
            ->get(route('koperasi.dashboard', ['koperasi' => $this->kopA->slug]))
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_admin_cannot_deactivate_self_or_manage_other_koperasi_employee(): void
    {
        Livewire::actingAs($this->adminA, 'web')
            ->test('ordering::employee-manager', ['koperasiId' => $this->kopA->id])
            ->call('toggleActive', $this->adminA->id)
            ->assertHasErrors('employee');

        $this->assertTrue($this->adminA->fresh()->is_active);

        $kasirB = User::query()->where('email', 'kasir.lantamal5@koperasi.test')->firstOrFail();

        // Karyawan koperasi lain menghasilkan 404 Not Found dan datanya tetap utuh
        Livewire::actingAs($this->adminA, 'web')
            ->test('ordering::employee-manager', ['koperasiId' => $this->kopA->id])
            ->call('toggleActive', $kasirB->id)
            ->assertNotFound();

        $this->assertTrue($kasirB->fresh()->is_active);
    }
}
