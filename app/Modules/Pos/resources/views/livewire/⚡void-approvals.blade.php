<?php

use App\Models\Koperasi;
use App\Models\Sale;
use App\Models\User;
use App\Modules\Pos\Exceptions\PosException;
use App\Modules\Pos\Services\PosVoidService;
use App\Support\Tenancy\KoperasiContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $koperasiId = 0;

    /** @var array<int, string> */
    public array $notes = [];

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

    /** @return Collection<int, Sale> */
    #[Computed]
    public function pendingVoids(): Collection
    {
        return Sale::query()
            ->with(['user:id,name', 'items'])
            ->where('status', 'void_requested')
            ->latest('updated_at')
            ->get();
    }

    /** @return Collection<int, Sale> */
    #[Computed]
    public function voidedSales(): Collection
    {
        return Sale::query()
            ->with(['user:id,name', 'items'])
            ->where('status', 'voided')
            ->latest('updated_at')
            ->limit(20)
            ->get();
    }

    public function approve(int $saleId): void
    {
        $this->resetErrorBag('voidReview');
        $sale = Sale::query()->findOrFail($saleId);
        $koperasi = app(KoperasiContext::class)->koperasi();
        /** @var User $admin */
        $admin = Auth::guard('web')->user();

        try {
            app(PosVoidService::class)->approveVoid($koperasi, $sale, $admin, $this->notes[$saleId] ?? '');
            unset($this->notes[$saleId], $this->pendingVoids, $this->voidedSales);
            session()->flash('status', "Void {$sale->invoice_number} disetujui dan stok telah dikembalikan.");
        } catch (PosException $e) {
            $this->addError('voidReview', $e->getMessage());
        }
    }

    public function reject(int $saleId): void
    {
        $this->resetErrorBag('voidReview');
        $sale = Sale::query()->findOrFail($saleId);
        $koperasi = app(KoperasiContext::class)->koperasi();
        /** @var User $admin */
        $admin = Auth::guard('web')->user();

        try {
            app(PosVoidService::class)->rejectVoid($koperasi, $sale, $admin, $this->notes[$saleId] ?? '');
            unset($this->notes[$saleId], $this->pendingVoids);
            session()->flash('status', "Void {$sale->invoice_number} ditolak; transaksi kembali berstatus lunas.");
        } catch (PosException $e) {
            $this->addError('voidReview', $e->getMessage());
        }
    }

    public function markRefunded(int $saleId): void
    {
        $this->resetErrorBag('voidReview');
        $sale = Sale::query()->findOrFail($saleId);
        $koperasi = app(KoperasiContext::class)->koperasi();
        /** @var User $admin */
        $admin = Auth::guard('web')->user();

        try {
            app(PosVoidService::class)->markRefunded($koperasi, $sale, $admin, $this->notes[$saleId] ?? '');
            unset($this->notes[$saleId], $this->voidedSales);
            session()->flash('status', "Transaksi {$sale->invoice_number} ditandai refunded (pengembalian dana manual selesai).");
        } catch (PosException $e) {
            $this->addError('voidReview', $e->getMessage());
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

    @error('voidReview')
        <div class="rounded-lg bg-red-100 px-4 py-3 text-sm font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300" role="alert">
            {{ $message }}
        </div>
    @enderror

    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-bold">Menunggu Persetujuan Void ({{ $this->pendingVoids->count() }})</h2>

        <div class="mt-4 space-y-4">
            @forelse ($this->pendingVoids as $sale)
                <article wire:key="pending-void-{{ $sale->id }}" class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <p class="font-mono text-sm font-bold">{{ $sale->invoice_number }}</p>
                            <p class="text-xs text-slate-500">Kasir: {{ $sale->user?->name }} · Metode: {{ strtoupper($sale->payment_method) }}</p>
                        </div>
                        <p class="text-lg font-extrabold">Rp {{ number_format((int) $sale->total_amount, 0, ',', '.') }}</p>
                    </div>

                    <ul class="mt-2 space-y-1 text-sm text-slate-600 dark:text-slate-300">
                        @foreach ($sale->items as $item)
                            <li>{{ $item->quantity }}× {{ $item->product_name }} — Rp {{ number_format((int) $item->subtotal, 0, ',', '.') }}</li>
                        @endforeach
                    </ul>

                    <div class="mt-3">
                        <x-input
                            name="note-{{ $sale->id }}"
                            label="Catatan Persetujuan / Alasan Penolakan (wajib jika menolak)"
                            wire:model="notes.{{ $sale->id }}"
                        />
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-button type="button" variant="danger" wire:click="approve({{ $sale->id }})">Setujui Void &amp; Kembalikan Stok</x-button>
                        <x-button type="button" variant="secondary" wire:click="reject({{ $sale->id }})">Tolak Void</x-button>
                    </div>
                </article>
            @empty
                <x-empty-state title="Tidak ada pengajuan void" description="Pengajuan void dari kasir akan muncul di sini untuk ditinjau Admin Koperasi." />
            @endforelse
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-bold">Void Disetujui — Menunggu Pencatatan Refund Luar Sistem</h2>

        <div class="mt-4 space-y-4">
            @forelse ($this->voidedSales as $sale)
                <article wire:key="voided-{{ $sale->id }}" class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="font-mono text-sm font-bold">{{ $sale->invoice_number }}</p>
                        <p class="font-bold">Rp {{ number_format((int) $sale->total_amount, 0, ',', '.') }}</p>
                    </div>
                    <div class="mt-3 flex flex-wrap items-end gap-2">
                        <div class="min-w-[16rem] flex-1">
                            <x-input
                                name="refund-{{ $sale->id }}"
                                label="Catatan Refund Manual (wajib)"
                                wire:model="notes.{{ $sale->id }}"
                                placeholder="Mis. Tunai dikembalikan ke pembeli sesuai SOP 1x24 jam"
                            />
                        </div>
                        <x-button type="button" wire:click="markRefunded({{ $sale->id }})">Tandai Refund Selesai</x-button>
                    </div>
                </article>
            @empty
                <p class="text-sm text-slate-400">Tidak ada transaksi voided yang menunggu pencatatan refund.</p>
            @endforelse
        </div>
    </section>
</div>