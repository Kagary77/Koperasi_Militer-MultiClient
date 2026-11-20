<?php

namespace App\Modules\Ordering\Services;

use App\Models\Koperasi;
use App\Models\User;
use App\Services\Shared\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class EmployeeService
{
    public const ALLOWED_ROLES = ['admin_koperasi', 'kasir'];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{name: string, email: string, role: string, password: string}  $data
     */
    public function create(Koperasi $koperasi, array $data): User
    {
        $this->assertValidRole($data['role']);

        return DB::transaction(function () use ($koperasi, $data): User {
            $employee = new User;
            $employee->forceFill([
                'koperasi_id' => $koperasi->id,
                'name' => trim($data['name']),
                'email' => Str::lower(trim($data['email'])),
                'password' => Hash::make($data['password']),
                'role' => $data['role'],
                'is_active' => true,
                'email_verified_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'employee_created',
                modelType: User::class,
                modelId: $employee->id,
                oldValues: null,
                newValues: $employee->only(['name', 'email', 'role', 'is_active']),
                koperasiId: $koperasi->id,
            );

            return $employee;
        });
    }

    /**
     * @param  array{name: string, email: string, role: string, password?: string|null}  $data
     */
    public function update(Koperasi $koperasi, User $employee, array $data, User $actor): User
    {
        $this->assertOwnedByKoperasi($koperasi, $employee);
        $this->assertValidRole($data['role']);

        if (
            $employee->role === 'admin_koperasi'
            && $data['role'] !== 'admin_koperasi'
            && $this->activeAdminCount($koperasi->id) <= 1
        ) {
            throw new DomainException('Tidak dapat mengubah peran Admin Koperasi aktif terakhir.');
        }

        return DB::transaction(function () use ($koperasi, $employee, $data): User {
            $before = $employee->only(['name', 'email', 'role', 'is_active']);

            $attributes = [
                'name' => trim($data['name']),
                'email' => Str::lower(trim($data['email'])),
                'role' => $data['role'],
            ];

            if (! empty($data['password'])) {
                $attributes['password'] = Hash::make((string) $data['password']);
            }

            $employee->forceFill($attributes)->save();

            $this->audit->record(
                action: 'employee_updated',
                modelType: User::class,
                modelId: $employee->id,
                oldValues: $before,
                newValues: $employee->only(['name', 'email', 'role', 'is_active']),
                koperasiId: $koperasi->id,
            );

            return $employee;
        });
    }

    public function setActive(Koperasi $koperasi, User $employee, bool $active, User $actor): User
    {
        $this->assertOwnedByKoperasi($koperasi, $employee);

        if (! $active && (int) $employee->id === (int) $actor->id) {
            throw new DomainException('Anda tidak dapat menonaktifkan akun Anda sendiri.');
        }

        if (
            ! $active
            && $employee->role === 'admin_koperasi'
            && $this->activeAdminCount($koperasi->id) <= 1
        ) {
            throw new DomainException('Tidak dapat menonaktifkan Admin Koperasi aktif terakhir.');
        }

        return DB::transaction(function () use ($koperasi, $employee, $active): User {
            $before = ['is_active' => $employee->is_active];

            $attributes = ['is_active' => $active];

            if (! $active) {
                $attributes['remember_token'] = Str::random(60);
            }

            $employee->forceFill($attributes)->save();

            $this->audit->record(
                action: $active ? 'employee_activated' : 'employee_deactivated',
                modelType: User::class,
                modelId: $employee->id,
                oldValues: $before,
                newValues: ['is_active' => $active],
                koperasiId: $koperasi->id,
            );

            return $employee;
        });
    }

    private function assertOwnedByKoperasi(Koperasi $koperasi, User $employee): void
    {
        if (
            (int) $employee->koperasi_id !== (int) $koperasi->id
            || ! in_array($employee->role, self::ALLOWED_ROLES, true)
        ) {
            throw new DomainException('Karyawan tidak ditemukan pada koperasi ini.');
        }
    }

    private function assertValidRole(string $role): void
    {
        if (! in_array($role, self::ALLOWED_ROLES, true)) {
            throw new DomainException('Peran karyawan tidak valid.');
        }
    }

    private function activeAdminCount(int $koperasiId): int
    {
        return User::query()
            ->where('koperasi_id', $koperasiId)
            ->where('role', 'admin_koperasi')
            ->where('is_active', true)
            ->count();
    }
}
