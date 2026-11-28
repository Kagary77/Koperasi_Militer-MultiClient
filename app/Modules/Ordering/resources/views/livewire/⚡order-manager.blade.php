<?php

use App\Models\Koperasi;
use App\Models\Order;
use App\Models\User;
use App\Modules\Ordering\Exceptions\OrderingException;
use App\Modules\Ordering\Services\KoperasiOrderService;
use App\Support\Tenancy\KoperasiContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $koperasiId = 0;

    public string $statusFilter = 'active';

    public string $search = '';

    // Form pembatalan pesanan oleh Admin Koperasi
    public ?int $cancellingOrderId = null;

    public string $cancellationReason = '';

    public function mount(int $koperasiId): void
    {
        $this->koperasiId = $koperasiId;
    }

    public function booted(): void
    {
        $user = Auth::guard('web')->user();

        abort_unless(
            $user instanceof User
            && $user->isActive()
            && in_array($user->role, ['admin_koperasi', 'kasir'], true)
            && (int) $user->koperasi_id === $this->koperasiId,
            403,
        );

        app(KoperasiContext::class)->set(Koperasi::query()->findOrFail($this->koperasiId));
    }

    #[Computed]
    public function isAdmin(): bool
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User && $user->isAdminKoperasi();
    }

    /** @return Collection<int, Order> */
    #[Computed]
    public function orders(): Collection
    {
        return Order::query()
            ->with(['customer:id,name,phone', 'items'])
            ->when($this->statusFilter === 'active', fn ($q) => $q->whereIn('status', ['paid', 'processing', 'ready']))
            ->when(
                in_array($this->statusFilter, ['pending_payment', 'paid', 'processing', 'ready', 'completed', 'cancelled'], true),
                fn ($q) => $q->where('status', $this->statusFilter),
            )
            ->when($this->search !== '', function ($query): void {
                $like = '%'.$this->search.'%';
                $query->where(function ($q) use ($like): void {
                    $q->where('order_number', 'like', $like)
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('phone', 'like', $like));
                });
            })
            ->latest('id')
            ->limit(30)
            ->get();
    }

    public function advance(int $orderId, string $nextStatus): void
    {
        $this->resetErrorBag('orderAction');

        $order = Order::query()->findOrFail($orderId);
        $koperasi = app(KoperasiContext::class)->koperasi();
        /** @var User $actor */
        $actor = Auth::guard('web')->user();

        try {
            app(KoperasiOrderService::class)->advanceStatus($koperasi, $order, $actor, $nextStatus);
            unset($this->orders);
            session()->flash('status', "Status pesanan {$order->order_number} berhasil diperbarui menjadi {$nextStatus}.");
        } catch (OrderingException $e) {
            $this->addError('orderAction', $e->getMessage());
        }
    }

    public function startCancel(int $orderId): void
    {
        abort_unless($this->isAdmin(), 403);

        $this->cancellingOrderId = $orderId;
        $this->cancellationReason = '';
        $this->resetErrorBag('orderAction');
    }

    public function abortCancel(): void
    {
        $this->reset(['cancellingOrderId', 'cancellationReason']);
    }

    public function confirmCancel(): void
    {
        abort_unless($this->isAdmin(), 403);

        if ($this->cancellingOrderId === null) {
            return;
        }

        $order = Order::query()->findOrFail($this->cancellingOrderId);
        $koperasi = app(KoperasiContext::class)->koperasi();
        /** @var User $admin */
        $admin = Auth::guard('web')->user();

        try {
            app(KoperasiOrderService::class)->cancelByAdmin($koperasi, $order, $admin, $this->cancellationReason);
            $this->abortCancel();
            unset($this->orders);
            session()->flash('status', "Pesanan {$order->order_number} dibatalkan, stok dikembalikan, dan pelanggan telah diberi tahu (SOP Refund 1×24 jam).");
        } catch (OrderingException $e) {
            $this->addError('orderAction', $e->getMessage());
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

    @error('orderAction')
        <div class="rounded-lg bg-red-100 px-4 py-3 text-sm font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300" role="alert">
            {{ $message }}
        </div>
    @enderror

    {{-- Filter Status & Pencarian --}}
    <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1.5">
                @php
                    $tabs = [
                        'active' => 'Perlu Diproses',
                        'paid' => 'Lunas',
                        'processing' => 'Diproses',
                        'ready' => 'Siap Diambil',
                        'completed' => 'Selesai',
                        'cancelled' => 'Dibatalkan',
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

            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Cari nomor order atau nama anggota…"
                class="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm sm:w-72 dark:border-slate-600 dark:bg-slate-800"
            />
        </div>
    </section>

    {{-- Daftar Kartu Pesanan --}}
    <section class="space-y-4">
        @forelse ($this->orders as $order)
            @php
                $badgeStatus = match ($order->status) {
                    'paid', 'completed' => 'active',
                    'processing', 'ready', 'pending_payment' => 'pending',
                    'cancelled' => 'inactive',
                    default => 'default',
                };

                $nextAction = match ($order->status) {
                    'paid' => ['target' => 'processing', 'label' => 'Mulai Proses Pesanan'],
                    'processing' => ['target' => 'ready', 'label' => 'Tandai Siap Diambil'],
                    'ready' => ['target' => 'completed', 'label' => 'Selesaikan Pengambilan'],
                    default => null,
                };
            @endphp

            <article wire:key="ord-{{ $order->id }}" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-3 dark:border-slate-800">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-sm font-bold">{{ $order->order_number }}</span>
                            <x-status-badge :status="$badgeStatus">{{ strtoupper(str_replace('_', ' ', $order->status)) }}</x-status-badge>
                        </div>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                            Pemesan: <strong>{{ $order->customer?->name ?? 'Anggota' }}</strong>
                            @if ($order->customer?->phone)
                                · <span class="font-mono text-xs">{{ $order->customer->phone }}</span>
                            @endif
                        </p>
                        <p class="text-xs text-slate-500">
                            Dibuat {{ $order->created_at->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="text-xs text-slate-500">Total Tagihan</p>
                        <p class="text-lg font-extrabold">Rp {{ number_format((int) $order->total_amount, 0, ',', '.') }}</p>
                    </div>
                </div>

                <ul class="mt-3 space-y-1 text-sm text-slate-700 dark:text-slate-300">
                    @foreach ($order->items as $item)
                        <li class="flex justify-between gap-4">
                            <span>{{ $item->quantity }}× {{ $item->product_name }}</span>
                            <span class="font-medium">Rp {{ number_format((int) $item->subtotal, 0, ',', '.') }}</span>
                        </li>
                    @endforeach
                </ul>

                @if ($order->cancellation_reason)
                    <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950/40 dark:text-red-300">
                        Alasan pembatalan: {{ $order->cancellation_reason }}
                    </p>
                @endif

                @if ($cancellingOrderId === $order->id && $this->isAdmin)
                    <form wire:submit="confirmCancel" class="mt-4 space-y-3 rounded-lg border-2 border-red-400 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950/30">
                        <p class="text-xs font-semibold text-red-800 dark:text-red-300">
                            Pembatalan akan mengembalikan stok secara otomatis dan memberitahu anggota terkait SOP Refund Luar Sistem (SLA 1×24 jam).
                        </p>
                        <x-input
                            name="cancellationReason-{{ $order->id }}"
                            label="Alasan Pembatalan (Wajib)"
                            wire:model="cancellationReason"
                            placeholder="Mis. Stok fisik rusak saat pengepakan"
                            required
                        />
                        <div class="flex flex-wrap gap-2">
                            <x-button type="submit" variant="danger">Konfirmasi Batalkan Pesanan</x-button>
                            <x-button type="button" variant="secondary" wire:click="abortCancel">Kembali</x-button>
                        </div>
                    </form>
                @else
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if ($nextAction !== null)
                            <x-button
                                type="button"
                                wire:click="advance({{ $order->id }}, '{{ $nextAction['target'] }}')"
                            >
                                {{ $nextAction['label'] }}
                            </x-button>
                        @endif

                        @if ($this->isAdmin && in_array($order->status, ['paid', 'processing'], true))
                            <x-button
                                type="button"
                                variant="danger"
                                wire:click="startCancel({{ $order->id }})"
                            >
                                Batalkan Pesanan
                            </x-button>
                        @endif
                    </div>
                @endif
            </article>
        @empty
            <x-empty-state
                title="Tidak ada pesanan pada filter ini"
                description="Pesanan online yang sudah dibayar oleh anggota akan muncul di sini."
            />
        @endforelse
    </section>
</div>