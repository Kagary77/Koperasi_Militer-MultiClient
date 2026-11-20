<?php

use App\Models\Koperasi;
use App\Models\User;
use App\Modules\Ordering\Services\EmployeeService;
use App\Support\Tenancy\KoperasiContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $koperasiId = 0;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'kasir';

    public string $password = '';

    public string $search = '';

    public function mount(int $koperasiId): void
    {
        $this->koperasiId = $koperasiId;
    }

    public function booted(): void
    {
        $user = Auth::guard('web')->user();

        abort_unless(
            $user instanceof User
            && $user->isAdminKoperasi()
            && (int) $user->koperasi_id === $this->koperasiId,
            403,
        );

        app(KoperasiContext::class)->set(Koperasi::query()->findOrFail($this->koperasiId));
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function employees(): Collection
    {
        return User::query()
            ->where('koperasi_id', $this->koperasiId)
            ->whereIn('role', EmployeeService::ALLOWED_ROLES)
            ->when($this->search !== '', function ($query): void {
                $like = '%'.$this->search.'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function save(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'email' => [
                'required',
                'email',
                'max:150',
                Rule::unique('users', 'email')->ignore($this->editingId),
            ],
            'role' => ['required', Rule::in(EmployeeService::ALLOWED_ROLES)],
            'password' => $this->editingId === null
                ? ['required', 'string', 'min:8', 'max:100']
                : ['nullable', 'string', 'min:8', 'max:100'],
        ];

        $data = $this->validate($rules, [
            'email.unique' => 'Email karyawan sudah terdaftar.',
        ], [
            'name' => 'nama',
            'email' => 'email',
            'role' => 'peran',
            'password' => 'kata sandi',
        ]);

        $koperasi = app(KoperasiContext::class)->koperasi();
        $service = app(EmployeeService::class);
        /** @var User $actor */
        $actor = Auth::guard('web')->user();

        try {
            if ($this->editingId === null) {
                $service->create($koperasi, $data);
                session()->flash('status', 'Akun karyawan berhasil dibuat.');
            } else {
                $employee = User::query()
                    ->where('koperasi_id', $this->koperasiId)
                    ->whereIn('role', EmployeeService::ALLOWED_ROLES)
                    ->findOrFail($this->editingId);

                $service->update($koperasi, $employee, $data, $actor);
                session()->flash('status', 'Data karyawan berhasil diperbarui.');
            }
        } catch (DomainException $e) {
            $this->addError('employee', $e->getMessage());

            return;
        }

        $this->resetForm();
    }

    public function edit(int $employeeId): void
    {
        $employee = User::query()
            ->where('koperasi_id', $this->koperasiId)
            ->whereIn('role', EmployeeService::ALLOWED_ROLES)
            ->findOrFail($employeeId);

        $this->editingId = $employee->id;
        $this->name = $employee->name;
        $this->email = $employee->email;
        $this->role = $employee->role;
        $this->password = '';
        $this->resetValidation();
    }

    public function toggleActive(int $employeeId): void
    {
        $employee = User::query()
            ->where('koperasi_id', $this->koperasiId)
            ->whereIn('role', EmployeeService::ALLOWED_ROLES)
            ->findOrFail($employeeId);

        $koperasi = app(KoperasiContext::class)->koperasi();
        /** @var User $actor */
        $actor = Auth::guard('web')->user();

        try {
            $updated = app(EmployeeService::class)->setActive($koperasi, $employee, ! $employee->is_active, $actor);
            session()->flash(
                'status',
                $updated->is_active ? 'Akun karyawan diaktifkan kembali.' : 'Akun karyawan dinonaktifkan dan sesi aktifnya diakhiri.',
            );
        } catch (DomainException $e) {
            $this->addError('employee', $e->getMessage());
        }

        unset($this->employees);
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'email', 'password']);
        $this->role = 'kasir';
        $this->resetValidation();
        unset($this->employees);
    }
};
?>

<div class="space-y-6">
    @if (session('status'))
        <div class="rounded-lg bg-emerald-100 px-4 py-3 text-sm font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    @error('employee')
        <div class="rounded-lg bg-red-100 px-4 py-3 text-sm font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300" role="alert">
            {{ $message }}
        </div>
    @enderror

    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-bold">{{ $editingId ? 'Ubah Data Karyawan' : 'Tambah Karyawan Baru' }}</h2>

        <form wire:submit="save" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <x-input name="name" label="Nama Lengkap" wire:model="name" required />
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-input name="email" label="Email" type="email" wire:model="email" required />
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="role" class="text-sm font-medium text-slate-700 dark:text-slate-200">Peran</label>
                <select
                    id="role"
                    wire:model="role"
                    class="min-h-11 rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                >
                    <option value="kasir">Kasir</option>
                    <option value="admin_koperasi">Admin Koperasi</option>
                </select>
                @error('role') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-input
                    name="password"
                    :label="$editingId ? 'Kata Sandi Baru (kosongkan jika tidak diubah)' : 'Kata Sandi Awal (min. 8 karakter)'"
                    type="password"
                    wire:model="password"
                />
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap gap-2 sm:col-span-2">
                <x-button type="submit">{{ $editingId ? 'Simpan Perubahan' : 'Tambah Karyawan' }}</x-button>
                @if ($editingId)
                    <x-button type="button" variant="secondary" wire:click="cancelEdit">Batal</x-button>
                @endif
            </div>
        </form>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-bold">Daftar Karyawan</h2>
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Cari nama atau email…"
                class="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm sm:w-64 dark:border-slate-600 dark:bg-slate-800"
            />
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[40rem] text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-slate-800">
                    <tr>
                        <th class="py-3 pe-4">Nama</th>
                        <th class="py-3 pe-4">Email</th>
                        <th class="py-3 pe-4">Peran</th>
                        <th class="py-3 pe-4">Status</th>
                        <th class="py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($this->employees as $employee)
                        <tr wire:key="emp-{{ $employee->id }}">
                            <td class="py-3 pe-4 font-medium">{{ $employee->name }}</td>
                            <td class="py-3 pe-4 font-mono text-xs">{{ $employee->email }}</td>
                            <td class="py-3 pe-4">{{ $employee->role === 'admin_koperasi' ? 'Admin Koperasi' : 'Kasir' }}</td>
                            <td class="py-3 pe-4">
                                <x-status-badge :status="$employee->is_active ? 'active' : 'inactive'">
                                    {{ $employee->is_active ? 'Aktif' : 'Nonaktif' }}
                                </x-status-badge>
                            </td>
                            <td class="py-3 text-right">
                                <div class="inline-flex items-center gap-2">
                                    <button
                                        type="button"
                                        wire:click="edit({{ $employee->id }})"
                                        class="min-h-11 rounded-lg border border-slate-300 px-3 text-xs font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                                    >
                                        Ubah
                                    </button>
                                    @if ((int) $employee->id !== (int) auth('web')->id())
                                        <button
                                            type="button"
                                            wire:click="toggleActive({{ $employee->id }})"
                                            class="min-h-11 rounded-lg border px-3 text-xs font-medium {{ $employee->is_active ? 'border-red-300 text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400' : 'border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-400' }}"
                                        >
                                            {{ $employee->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-6">
                                <x-empty-state title="Belum ada karyawan" description="Tambahkan akun kasir atau admin koperasi melalui formulir di atas." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>