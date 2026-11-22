<?php

use App\Models\CashierShift;
use App\Models\Koperasi;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\PaymentWebhookService;
use App\Modules\Payments\Services\QrisImage;
use App\Modules\Payments\Services\WebhookSignatureVerifier;
use App\Modules\Pos\Exceptions\PosException;
use App\Modules\Pos\Services\CashierShiftService;
use App\Modules\Pos\Services\PosService;
use App\Modules\Pos\Services\PosVoidService;
use App\Support\Tenancy\KoperasiContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $koperasiId = 0;

    public string $koperasiSlug = '';

    // Shift Kasir
    public int $openingBalance = 0;

    public bool $showCloseShift = false;

    public int $actualCash = 0;

    /** @var array{opening: int, expected: int, actual: int, difference: int}|null */
    public ?array $closedSummary = null;

    // Keranjang POS: [product_id => quantity]
    /** @var array<int, int> */
    public array $cart = [];

    public string $barcodeInput = '';

    public string $search = '';

    // Pembayaran
    public string $paymentMethod = 'cash';

    public int $amountPaid = 0;

    public ?int $lastSaleId = null;

    // Pembayaran QRIS Aktif
    public ?int $activeQrisPaymentId = null;

    // Pengajuan Void oleh Kasir
    public ?int $voidingSaleId = null;

    public string $voidReason = '';

    public function mount(int $koperasiId, string $koperasiSlug): void
    {
        $this->koperasiId = $koperasiId;
        $this->koperasiSlug = $koperasiSlug;
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
    public function activeShift(): ?CashierShift
    {
        $koperasi = app(KoperasiContext::class)->koperasi();
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return app(CashierShiftService::class)->activeShift($koperasi, $user);
    }

    /** @return Collection<int, Product> */
    #[Computed]
    public function products(): Collection
    {
        return Product::query()
            ->sellable()
            ->with('unit:id,name')
            ->when($this->search !== '', function ($query): void {
                $like = '%'.$this->search.'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('barcode', 'like', $like));
            })
            ->orderBy('name')
            ->limit(24)
            ->get();
    }

    /** @return list<array{product: Product, quantity: int, subtotal: int}> */
    #[Computed]
    public function cartLines(): array
    {
        if ($this->cart === []) {
            return [];
        }

        $products = Product::query()
            ->with('unit:id,name')
            ->whereIn('id', array_keys($this->cart))
            ->get()
            ->keyBy('id');

        $lines = [];
        foreach ($this->cart as $productId => $qty) {
            /** @var Product|null $product */
            $product = $products->get($productId);
            if ($product === null || $qty < 1) {
                continue;
            }

            $lines[] = [
                'product' => $product,
                'quantity' => $qty,
                'subtotal' => (int) $product->price * $qty,
            ];
        }

        return $lines;
    }

    #[Computed]
    public function cartTotal(): int
    {
        return array_sum(array_column($this->cartLines, 'subtotal'));
    }

    /** @return Collection<int, Sale> */
    #[Computed]
    public function shiftSales(): Collection
    {
        $shift = $this->activeShift;
        if ($shift === null) {
            return new Collection;
        }

        return Sale::query()
            ->with('items')
            ->where('shift_id', $shift->id)
            ->latest('id')
            ->limit(15)
            ->get();
    }

    #[Computed]
    public function lastSale(): ?Sale
    {
        if ($this->lastSaleId === null) {
            return null;
        }

        return Sale::query()->with('items')->find($this->lastSaleId);
    }

    #[Computed]
    public function activeQrisPayment(): ?Payment
    {
        if ($this->activeQrisPaymentId === null) {
            return null;
        }

        return Payment::query()
            ->where('koperasi_id', $this->koperasiId)
            ->with('sale')
            ->find($this->activeQrisPaymentId);
    }

    #[Computed]
    public function activeQrisSvg(): ?string
    {
        $payment = $this->activeQrisPayment;
        if ($payment === null || ! $payment->qris_payload) {
            return null;
        }

        return app(QrisImage::class)->svg((string) $payment->qris_payload, 220);
    }

    #[Computed]
    public function canSimulateSandboxQris(): bool
    {
        return app()->environment(['local', 'testing']) && app(PaymentGateway::class)->isSandbox();
    }

    public function openShift(): void
    {
        $this->validate([
            'openingBalance' => ['required', 'integer', 'min:0'],
        ], [], ['openingBalance' => 'modal awal']);

        $koperasi = app(KoperasiContext::class)->koperasi();
        /** @var User $user */
        $user = Auth::guard('web')->user();

        try {
            app(CashierShiftService::class)->open($koperasi, $user, $this->openingBalance);
            $this->closedSummary = null;
            $this->openingBalance = 0;
            unset($this->activeShift);
            session()->flash('status', 'Shift kasir berhasil dibuka.');
        } catch (PosException $e) {
            $this->addError('pos', $e->getMessage());
        }
    }

    public function closeShift(): void
    {
        $this->validate([
            'actualCash' => ['required', 'integer', 'min:0'],
        ], [], ['actualCash' => 'kas fisik']);

        $shift = $this->activeShift;
        if ($shift === null) {
            return;
        }

        $koperasi = app(KoperasiContext::class)->koperasi();
        /** @var User $user */
        $user = Auth::guard('web')->user();

        try {
            $closed = app(CashierShiftService::class)->close($koperasi, $shift, $user, $this->actualCash);
            $this->closedSummary = [
                'opening' => (int) $closed->opening_balance,
                'expected' => (int) $closed->expected_cash,
                'actual' => (int) $closed->actual_cash,
                'difference' => (int) $closed->difference,
            ];
            $this->reset(['showCloseShift', 'actualCash', 'cart', 'amountPaid', 'lastSaleId', 'activeQrisPaymentId']);
            unset($this->activeShift, $this->shiftSales, $this->activeQrisPayment);
            session()->flash('status', 'Shift kasir ditutup. Rekap kas tercatat.');
        } catch (PosException $e) {
            $this->addError('pos', $e->getMessage());
        }
    }

    public function scanBarcode(): void
    {
        $code = trim($this->barcodeInput);
        $this->barcodeInput = '';

        if ($code === '') {
            return;
        }

        $product = Product::query()->sellable()->where('barcode', $code)->first();

        if ($product === null) {
            $this->addError('pos', "Produk dengan barcode \"{$code}\" tidak ditemukan atau sedang habis.");

            return;
        }

        $this->addToCart((int) $product->id);
    }

    public function addToCart(int $productId): void
    {
        $this->resetErrorBag('pos');

        $product = Product::query()->sellable()->find($productId);
        if ($product === null) {
            $this->addError('pos', 'Produk tidak tersedia atau stok habis.');

            return;
        }

        $currentQty = $this->cart[$productId] ?? 0;
        if ($currentQty + 1 > (int) $product->stock) {
            $this->addError('pos', "Stok produk \"{$product->name}\" hanya tersisa {$product->stock}.");

            return;
        }

        $this->cart[$productId] = $currentQty + 1;
        unset($this->cartLines, $this->cartTotal);

        if ($this->paymentMethod === 'cash') {
            $this->amountPaid = $this->cartTotal;
        }
    }

    public function updateQty(int $productId, int $delta): void
    {
        if (! isset($this->cart[$productId])) {
            return;
        }

        $next = $this->cart[$productId] + $delta;

        if ($next <= 0) {
            unset($this->cart[$productId]);
        } else {
            $product = Product::query()->find($productId);
            if ($product === null || $next > (int) $product->stock) {
                $this->addError('pos', 'Jumlah melebihi stok tersedia.');

                return;
            }
            $this->cart[$productId] = $next;
        }

        unset($this->cartLines, $this->cartTotal);

        if ($this->paymentMethod === 'cash') {
            $this->amountPaid = $this->cartTotal;
        }
    }

    public function clearCart(): void
    {
        $this->cart = [];
        $this->amountPaid = 0;
        unset($this->cartLines, $this->cartTotal);
    }

    public function setExactCash(): void
    {
        $this->paymentMethod = 'cash';
        $this->amountPaid = $this->cartTotal;
    }

    public function checkout(): void
    {
        $this->resetErrorBag('pos');

        $koperasi = app(KoperasiContext::class)->koperasi();
        /** @var User $user */
        $user = Auth::guard('web')->user();

        try {
            $sale = app(PosService::class)->checkout(
                koperasi: $koperasi,
                cashier: $user,
                items: $this->cart,
                paymentMethod: $this->paymentMethod,
                amountPaid: $this->paymentMethod === 'cash' ? $this->amountPaid : 0,
            );

            $this->lastSaleId = $sale->id;
            $this->cart = [];
            $this->amountPaid = 0;

            if ($sale->payment_method === 'qris') {
                $payment = app(PaymentService::class)->initiateForSale($sale);
                $this->activeQrisPaymentId = $payment->id;
                unset($this->activeQrisPayment, $this->activeQrisSvg);
                session()->flash('status', "QRIS dinamis {$sale->invoice_number} siap dipindai pembeli.");
            } else {
                $this->activeQrisPaymentId = null;
                session()->flash(
                    'status',
                    "Transaksi {$sale->invoice_number} lunas (Tunai). Kembalian: Rp ".number_format((int) $sale->change_amount, 0, ',', '.'),
                );
            }

            unset($this->cartLines, $this->cartTotal, $this->products, $this->shiftSales, $this->lastSale);
        } catch (PosException|PaymentException $e) {
            $this->addError('pos', $e->getMessage());
        }
    }

    public function refreshQrisStatus(): void
    {
        $payment = $this->activeQrisPayment;
        if ($payment === null) {
            return;
        }

        $fresh = $payment->fresh(['sale']);
        unset($this->activeQrisPayment, $this->activeQrisSvg, $this->shiftSales, $this->products, $this->lastSale);

        if ($fresh !== null && $fresh->isSettled()) {
            $this->activeQrisPaymentId = null;
            session()->flash('status', "Pembayaran QRIS {$fresh->sale?->invoice_number} lunas dan stok telah diperbarui.");
        }
    }

    public function simulateSandboxQrisSettlement(): void
    {
        abort_unless($this->canSimulateSandboxQris, 403);

        $payment = $this->activeQrisPayment;
        if ($payment === null || $payment->status !== 'pending') {
            return;
        }

        $koperasi = app(KoperasiContext::class)->koperasi();
        $secret = (string) ($koperasi->gateway_credentials['webhook_secret'] ?? '');

        $payload = [
            'event_id' => 'SBX-EVT-'.strtoupper(Str::random(12)),
            'payment_reference' => $payment->payment_reference,
            'status' => 'settled',
            'amount' => (int) $payment->amount,
        ];
        $rawBody = (string) json_encode($payload);
        $signature = app(WebhookSignatureVerifier::class)->sign($rawBody, $secret);

        app(PaymentWebhookService::class)->handleWebhook($rawBody, $signature, $payload);
        $this->refreshQrisStatus();
    }

    public function dismissQrisPanel(): void
    {
        $this->activeQrisPaymentId = null;
        unset($this->activeQrisPayment, $this->activeQrisSvg);
    }

    public function startVoid(int $saleId): void
    {
        $this->voidingSaleId = $saleId;
        $this->voidReason = '';
        $this->resetErrorBag('void');
    }

    public function cancelVoid(): void
    {
        $this->reset(['voidingSaleId', 'voidReason']);
    }

    public function submitVoidRequest(): void
    {
        if ($this->voidingSaleId === null) {
            return;
        }

        $sale = Sale::query()->findOrFail($this->voidingSaleId);
        $koperasi = app(KoperasiContext::class)->koperasi();
        /** @var User $user */
        $user = Auth::guard('web')->user();

        try {
            app(PosVoidService::class)->requestVoid($koperasi, $sale, $user, $this->voidReason);
            $this->cancelVoid();
            unset($this->shiftSales);
            session()->flash('status', "Pengajuan void untuk {$sale->invoice_number} telah dikirim ke Admin Koperasi.");
        } catch (PosException $e) {
            $this->addError('void', $e->getMessage());
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

    @error('pos')
        <div class="rounded-lg bg-red-100 px-4 py-3 text-sm font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300" role="alert">
            {{ $message }}
        </div>
    @enderror

    @if ($closedSummary !== null)
        <section class="rounded-xl border border-emerald-300 bg-emerald-50 p-5 dark:border-emerald-800 dark:bg-emerald-950/30" data-test="shift-summary">
            <h2 class="text-base font-bold text-emerald-900 dark:text-emerald-200">Rekap Penutupan Shift</h2>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div><dt class="text-slate-500">Modal Awal</dt><dd class="font-bold">Rp {{ number_format($closedSummary['opening'], 0, ',', '.') }}</dd></div>
                <div><dt class="text-slate-500">Kas Sistem</dt><dd class="font-bold">Rp {{ number_format($closedSummary['expected'], 0, ',', '.') }}</dd></div>
                <div><dt class="text-slate-500">Kas Fisik</dt><dd class="font-bold">Rp {{ number_format($closedSummary['actual'], 0, ',', '.') }}</dd></div>
                <div>
                    <dt class="text-slate-500">Selisih</dt>
                    <dd class="font-bold {{ $closedSummary['difference'] < 0 ? 'text-red-600' : 'text-emerald-700' }}">
                        {{ $closedSummary['difference'] >= 0 ? '+' : '-' }}Rp {{ number_format(abs($closedSummary['difference']), 0, ',', '.') }}
                    </dd>
                </div>
            </dl>
        </section>
    @endif

    @if ($this->activeShift === null)
        <section class="mx-auto max-w-md rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-lg font-bold">Buka Shift Kasir</h2>
            <p class="mt-1 text-sm text-slate-500">Masukkan modal awal uang tunai di laci kasir sebelum memulai transaksi POS.</p>

            <form wire:submit="openShift" class="mt-4 space-y-4">
                <div>
                    <x-input name="openingBalance" label="Modal Awal Kasir (Rp)" type="number" min="0" wire:model="openingBalance" required />
                    @error('openingBalance') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <x-button type="submit" class="w-full">Buka Shift Sekarang</x-button>
            </form>
        </section>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
            <div class="text-sm">
                <span class="font-semibold text-emerald-700 dark:text-emerald-400">● Shift Aktif</span>
                <span class="ms-2 text-slate-500">
                    Mulai {{ $this->activeShift->start_time->setTimezone(config('app.display_timezone'))->format('H:i') }} WIB ·
                    Modal Awal Rp {{ number_format((int) $this->activeShift->opening_balance, 0, ',', '.') }}
                </span>
            </div>
            <x-button type="button" variant="secondary" wire:click="$toggle('showCloseShift')">
                {{ $showCloseShift ? 'Batal Tutup Shift' : 'Tutup Shift & Rekap Kas' }}
            </x-button>
        </div>

        @if ($showCloseShift)
            <section class="rounded-xl border-2 border-slate-900 bg-white p-5 dark:border-slate-100 dark:bg-slate-900">
                <h2 class="text-base font-bold">Tutup Shift Kasir</h2>
                <form wire:submit="closeShift" class="mt-3 flex flex-wrap items-end gap-3">
                    <div class="min-w-[14rem] flex-1">
                        <x-input name="actualCash" label="Total Uang Fisik di Laci (Rp)" type="number" min="0" wire:model="actualCash" required />
                        @error('actualCash') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <x-button type="submit" variant="danger">Konfirmasi Tutup Shift</x-button>
                </form>
            </section>
        @endif

        {{-- Panel QRIS Dinamis Aktif (UC-08 & UC-09) --}}
        @if ($this->activeQrisPayment)
            <section
                wire:poll.3s="refreshQrisStatus"
                data-test="pos-qris-panel"
                class="flex flex-col items-center gap-4 rounded-xl border-2 border-slate-900 bg-white p-6 text-center sm:flex-row sm:text-left dark:border-slate-200 dark:bg-slate-900"
            >
                <div class="rounded-xl border border-slate-200 bg-white p-3">
                    {!! $this->activeQrisSvg !!}
                </div>
                <div class="flex-1 space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pembayaran QRIS Dinamis</p>
                    <p class="font-mono text-sm font-bold">{{ $this->activeQrisPayment->sale?->invoice_number }} · {{ $this->activeQrisPayment->payment_reference }}</p>
                    <p class="text-2xl font-extrabold">Rp {{ number_format((int) $this->activeQrisPayment->amount, 0, ',', '.') }}</p>
                    <p class="text-xs text-slate-500">
                        Berlaku hingga {{ $this->activeQrisPayment->expired_at->setTimezone(config('app.display_timezone'))->format('H:i:s') }} WIB · Status: <strong class="uppercase">{{ $this->activeQrisPayment->status }}</strong>
                    </p>
                    <div class="flex flex-wrap justify-center gap-2 pt-2 sm:justify-start">
                        <x-button type="button" variant="secondary" wire:click="refreshQrisStatus">Cek Status</x-button>
                        @if ($this->canSimulateSandboxQris && $this->activeQrisPayment->status === 'pending')
                            <x-button type="button" wire:click="simulateSandboxQrisSettlement">Simulasi Bayar QRIS (Sandbox)</x-button>
                        @endif
                        <x-button type="button" variant="secondary" wire:click="dismissQrisPanel">Tutup Panel</x-button>
                    </div>
                </div>
            </section>
        @endif

        {{-- Banner Transaksi Terakhir & Tombol Cetak Struk --}}
        @if ($this->lastSale)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm dark:border-emerald-800 dark:bg-emerald-950/30">
                <div>
                    <span class="font-bold">{{ $this->lastSale->invoice_number }}</span>
                    · Total Rp {{ number_format((int) $this->lastSale->total_amount, 0, ',', '.') }}
                    · Status: <span class="font-semibold uppercase">{{ $this->lastSale->status }}</span>
                </div>
                <a
                    href="{{ route('koperasi.pos.receipt', ['koperasi' => $koperasiSlug, 'sale' => $this->lastSale->id]) }}"
                    target="_blank"
                    class="inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-4 font-semibold text-white dark:bg-white dark:text-slate-900"
                >
                    Cetak Struk
                </a>
            </div>
        @endif

        {{-- Grid Terminal POS --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <section class="space-y-4 lg:col-span-7">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <form wire:submit="scanBarcode" class="flex gap-2">
                        <input
                            type="text"
                            wire:model="barcodeInput"
                            placeholder="Pindai barcode lalu Enter…"
                            autofocus
                            class="min-h-11 flex-1 rounded-lg border-2 border-slate-900 bg-white px-3 text-sm font-mono dark:border-slate-300 dark:bg-slate-800"
                        />
                        <x-button type="submit">Scan</x-button>
                    </form>
                    <input
                        type="search"
                        wire:model.live.debounce.250ms="search"
                        placeholder="Cari nama produk…"
                        class="min-h-11 rounded-lg border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-800"
                    />
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @forelse ($this->products as $product)
                        <button
                            type="button"
                            wire:key="pos-prod-{{ $product->id }}"
                            wire:click="addToCart({{ $product->id }})"
                            class="flex min-h-24 flex-col justify-between rounded-xl border border-slate-200 bg-white p-3 text-left transition hover:border-slate-900 active:scale-[0.99] dark:border-slate-800 dark:bg-slate-900 dark:hover:border-slate-300"
                        >
                            <div>
                                <p class="line-clamp-2 text-sm font-bold">{{ $product->name }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">Stok: {{ $product->stock }} {{ $product->unit?->name }}</p>
                            </div>
                            <p class="mt-2 text-sm font-extrabold text-slate-900 dark:text-white">
                                Rp {{ number_format((int) $product->price, 0, ',', '.') }}
                            </p>
                        </button>
                    @empty
                        <div class="col-span-full">
                            <x-empty-state title="Produk tidak ditemukan" description="Pastikan produk tersedia dan memiliki stok lebih dari nol." />
                        </div>
                    @endforelse
                </div>
            </section>

            <section class="flex flex-col rounded-xl border border-slate-200 bg-white p-5 lg:col-span-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between border-b border-slate-200 pb-3 dark:border-slate-800">
                    <h2 class="text-base font-bold">Keranjang POS</h2>
                    @if ($cart !== [])
                        <button type="button" wire:click="clearCart" class="min-h-11 px-2 text-xs font-semibold text-red-600 underline">Kosongkan</button>
                    @endif
                </div>

                <div class="mt-3 flex-1 space-y-2">
                    @forelse ($this->cartLines as $line)
                        <div wire:key="cart-{{ $line['product']->id }}" class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 p-2.5 dark:border-slate-800">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold">{{ $line['product']->name }}</p>
                                <p class="text-xs text-slate-500">Rp {{ number_format((int) $line['product']->price, 0, ',', '.') }} × {{ $line['quantity'] }}</p>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" wire:click="updateQty({{ $line['product']->id }}, -1)" class="min-h-11 min-w-11 rounded-lg border border-slate-300 text-base font-bold dark:border-slate-600">−</button>
                                <span class="w-8 text-center text-sm font-bold">{{ $line['quantity'] }}</span>
                                <button type="button" wire:click="updateQty({{ $line['product']->id }}, 1)" class="min-h-11 min-w-11 rounded-lg border border-slate-300 text-base font-bold dark:border-slate-600">+</button>
                            </div>
                            <p class="w-24 text-right text-sm font-bold">Rp {{ number_format($line['subtotal'], 0, ',', '.') }}</p>
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-slate-400">Pindai barcode atau ketuk produk untuk menambahkan.</p>
                    @endforelse
                </div>

                <div class="mt-4 space-y-4 border-t border-slate-200 pt-4 dark:border-slate-800">
                    <div class="flex items-baseline justify-between">
                        <span class="text-sm font-semibold text-slate-500">Total Belanja</span>
                        <span class="text-2xl font-extrabold" data-test="pos-total">Rp {{ number_format($this->cartTotal, 0, ',', '.') }}</span>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            wire:click="$set('paymentMethod', 'cash')"
                            @class([
                                'min-h-11 rounded-lg border-2 text-sm font-bold transition',
                                'border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900' => $paymentMethod === 'cash',
                                'border-slate-300 text-slate-700 dark:border-slate-700 dark:text-slate-200' => $paymentMethod !== 'cash',
                            ])
                        >
                            Tunai
                        </button>
                        <button
                            type="button"
                            wire:click="$set('paymentMethod', 'qris')"
                            @class([
                                'min-h-11 rounded-lg border-2 text-sm font-bold transition',
                                'border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900' => $paymentMethod === 'qris',
                                'border-slate-300 text-slate-700 dark:border-slate-700 dark:text-slate-200' => $paymentMethod !== 'qris',
                            ])
                        >
                            QRIS Dinamis
                        </button>
                    </div>

                    @if ($paymentMethod === 'cash')
                        <div class="flex items-end gap-2">
                            <div class="flex-1">
                                <x-input name="amountPaid" label="Uang Diterima (Rp)" type="number" min="0" wire:model.live="amountPaid" />
                            </div>
                            <x-button type="button" variant="secondary" wire:click="setExactCash">Uang Pas</x-button>
                        </div>
                        @if ($this->cartTotal > 0 && $amountPaid >= $this->cartTotal)
                            <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                Kembalian: Rp {{ number_format($amountPaid - $this->cartTotal, 0, ',', '.') }}
                            </p>
                        @endif
                    @else
                        <p class="text-xs text-slate-500">Transaksi QRIS dibuat berstatus <strong>open</strong>; stok dipotong saat pembayaran QRIS terverifikasi (UC-09).</p>
                    @endif

                    <x-button type="button" wire:click="checkout" :disabled="$cart === []" class="w-full">
                        {{ $paymentMethod === 'cash' ? 'Bayar Tunai & Selesai' : 'Tampilkan QRIS Dinamis' }}
                    </x-button>
                </div>
            </section>
        </div>

        {{-- Riwayat Transaksi Shift Ini + Pengajuan Void --}}
        <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-base font-bold">Transaksi Shift Ini</h2>

            @if ($voidingSaleId !== null)
                <div class="mt-3 rounded-lg border-2 border-amber-500 bg-amber-50 p-4 dark:bg-amber-950/30">
                    <p class="text-sm font-bold">Ajukan Void Transaksi (Memerlukan Persetujuan Admin Koperasi)</p>
                    @error('void') <p class="mt-1 text-xs font-semibold text-red-600">{{ $message }}</p> @enderror
                    <form wire:submit="submitVoidRequest" class="mt-2 flex flex-wrap items-end gap-2">
                        <div class="min-w-[16rem] flex-1">
                            <x-input name="voidReason" label="Alasan Void" wire:model="voidReason" placeholder="Mis. Salah input jumlah barang" required />
                        </div>
                        <x-button type="submit" variant="danger">Kirim Pengajuan Void</x-button>
                        <x-button type="button" variant="secondary" wire:click="cancelVoid">Batal</x-button>
                    </form>
                </div>
            @endif

            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[42rem] text-left text-sm">
                    <thead class="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-slate-800">
                        <tr>
                            <th class="py-2 pe-3">Invoice</th>
                            <th class="py-2 pe-3">Metode</th>
                            <th class="py-2 pe-3">Total</th>
                            <th class="py-2 pe-3">Status</th>
                            <th class="py-2 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @forelse ($this->shiftSales as $sale)
                            <tr wire:key="sale-{{ $sale->id }}">
                                <td class="py-2.5 pe-3 font-mono text-xs font-semibold">{{ $sale->invoice_number }}</td>
                                <td class="py-2.5 pe-3 uppercase">{{ $sale->payment_method }}</td>
                                <td class="py-2.5 pe-3 font-bold">Rp {{ number_format((int) $sale->total_amount, 0, ',', '.') }}</td>
                                <td class="py-2.5 pe-3">
                                    <x-status-badge :status="$sale->status === 'paid' ? 'active' : ($sale->status === 'void_requested' ? 'pending' : 'default')">
                                        {{ $sale->status }}
                                    </x-status-badge>
                                </td>
                                <td class="py-2.5 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <a
                                            href="{{ route('koperasi.pos.receipt', ['koperasi' => $koperasiSlug, 'sale' => $sale->id]) }}"
                                            target="_blank"
                                            class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-3 text-xs font-medium dark:border-slate-700"
                                        >
                                            Struk
                                        </a>
                                        @if ($sale->status === 'paid')
                                            <button
                                                type="button"
                                                wire:click="startVoid({{ $sale->id }})"
                                                class="min-h-11 rounded-lg border border-red-300 px-3 text-xs font-medium text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400"
                                            >
                                                Ajukan Void
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-6 text-center text-slate-400">Belum ada transaksi pada shift ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>