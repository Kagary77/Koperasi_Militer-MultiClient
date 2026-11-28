<?php

use App\Models\Complaint;
use App\Models\Koperasi;
use App\Models\User;
use App\Modules\Ordering\Exceptions\OrderingException;
use App\Modules\Ordering\Services\ComplaintService;
use App\Support\Tenancy\KoperasiContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $koperasiId = 0;

    public string $statusFilter = 'open';

    /** @var array<int, string> */
    public array $responses = [];

    /** @var array<int, string> */
    public array $targetStatuses = [];

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

    /** @return Collection<int, Complaint> */
    #[Computed]
    public function complaints(): Collection
    {
        return Complaint::query()
            ->with(['customer:id,name,phone', 'order:id,order_number'])
            ->when(
                in_array($this->statusFilter, ['open', 'processing', 'resolved', 'rejected'], true),
                fn ($q) => $q->where('status', $this->statusFilter),
            )
            ->latest('id')
            ->limit(30)
            ->get();
    }

    public function submitResponse(int $complaintId): void
    {
        $this->resetErrorBag('complaintAction');

        $complaint = Complaint::query()->findOrFail($complaintId);
        $koperasi = app(KoperasiContext::class)->koperasi();
        /** @var User $admin */
        $admin = Auth::guard('web')->user();

        $status = $this->targetStatuses[$complaintId] ?? 'resolved';
        $message = $this->responses[$complaintId] ?? '';

        try {
            app(ComplaintService::class)->respond($koperasi, $complaint, $admin, $status, $message);
            unset($this->responses[$complaintId], $this->targetStatuses[$complaintId], $this->complaints);
            session()->flash('status', "Tanggapan untuk pengaduan \"{$complaint->subject}\" telah dikirim ke anggota.");
        } catch (OrderingException $e) {
            $this->addError('complaintAction', $e->getMessage());
        }
    }
};
?>

<div class="space-y-6">
    @if (session('status'))
        <div class="rounded-lg bg-emerald-100 px-4 py-3 text-sm font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    @error('complaintAction')
        <div class="rounded-lg bg-red-100 px-4 py-3 text-sm font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300" role="alert">
            {{ $message }}
        </div>
    @enderror

    {{-- Filter Status Pengaduan --}}
    <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap gap-1.5">
            @php
                $tabs = [
                    'open' => 'Baru (Open)',
                    'processing' => 'Diproses',
                    'resolved' => 'Selesai',
                    'rejected' => 'Ditolak',
                    'all' => 'Semua',
                ];
            @endphp
            @foreach ($tabs as $key => $label)
                <button
                    type="button"
                    wire:click="$set('statusFilter', '{{ $key }}')"
                    @class([
                        'min-h-11 rounded-lg px-3 text-xs font-semibold transition',
                        'bg-slate-900 text-white dark:bg-white dark:text-slate-900' => $statusFilter === $key,
                        'border border-slate-300 text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800' => $statusFilter !== $key,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </section>

    {{-- Daftar Pengaduan --}}
    <section class="space-y-4">
        @forelse ($this->complaints as $complaint)
            @php
                $badgeStatus = match ($complaint->status) {
                    'resolved' => 'active',
                    'open', 'processing' => 'pending',
                    'rejected' => 'inactive',
                    default => 'default',
                };

                $categoryLabel = match ($complaint->category) {
                    'product_quality' => 'Kualitas Produk',
                    'service' => 'Layanan',
                    'payment' => 'Pembayaran',
                    default => 'Lainnya',
                };
            @endphp

            <article wire:key="cmp-{{ $complaint->id }}" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-base font-bold">{{ $complaint->subject }}</h3>
                            <x-status-badge :status="$badgeStatus">{{ strtoupper($complaint->status) }}</x-status-badge>
                            <span class="rounded-full border border-slate-200 px-2.5 py-0.5 text-xs text-slate-600 dark:border-slate-700 dark:text-slate-300">
                                {{ $categoryLabel }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            Pelapor: <strong>{{ $complaint->customer?->name ?? 'Anggota' }}</strong>
                            @if ($complaint->order)
                                · Pesanan: <span class="font-mono">{{ $complaint->order->order_number }}</span>
                            @endif
                            · {{ $complaint->created_at->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB
                        </p>
                    </div>
                </div>

                <p class="mt-3 text-sm text-slate-700 dark:text-slate-300">{{ $complaint->description }}</p>

                @if ($complaint->attachment_path)
                    <p class="mt-2">
                        <a
                            href="{{ Storage::disk('public')->url($complaint->attachment_path) }}"
                            target="_blank"
                            class="text-xs font-semibold text-slate-700 underline dark:text-slate-200"
                        >
                            Lihat Lampiran Bukti
                        </a>
                    </p>
                @endif

                @if ($complaint->admin_response)
                    <div class="mt-3 rounded-lg bg-slate-100 px-3 py-2 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        <strong>Tanggapan Admin:</strong> {{ $complaint->admin_response }}
                    </div>
                @endif

                @if (! in_array($complaint->status, ['resolved', 'rejected'], true))
                    <form wire:submit="submitResponse({{ $complaint->id }})" class="mt-4 space-y-3 border-t border-slate-200 pt-4 dark:border-slate-800">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div class="flex flex-col gap-1">
                                <label for="status-{{ $complaint->id }}" class="text-sm font-medium">Ubah Status</label>
                                <select
                                    id="status-{{ $complaint->id }}"
                                    wire:model="targetStatuses.{{ $complaint->id }}"
                                    class="min-h-11 rounded-lg border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-800"
                                >
                                    <option value="resolved">Selesai (Resolved)</option>
                                    <option value="processing">Sedang Diproses (Processing)</option>
                                    <option value="rejected">Ditolak (Rejected)</option>
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <x-input
                                    name="response-{{ $complaint->id }}"
                                    label="Tanggapan Resmi Admin Koperasi"
                                    wire:model="responses.{{ $complaint->id }}"
                                    placeholder="Tuliskan tindak lanjut atau solusi bagi anggota…"
                                    required
                                />
                            </div>
                        </div>
                        <x-button type="submit">Kirim Tanggapan</x-button>
                    </form>
                @endif
            </article>
        @empty
            <x-empty-state
                title="Tidak ada pengaduan pada filter ini"
                description="Pengaduan yang dikirim oleh anggota koperasi akan tampil di sini."
            />
        @endforelse
    </section>
</div>