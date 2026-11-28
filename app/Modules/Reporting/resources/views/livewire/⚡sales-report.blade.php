<?php

use App\Models\Koperasi;
use App\Models\User;
use App\Modules\Reporting\Services\KoperasiSalesReportService;
use App\Support\Tenancy\KoperasiContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $koperasiId = 0;

    public string $koperasiSlug = '';

    public string $startDate = '';

    public string $endDate = '';

    public string $channel = 'all';

    public function mount(int $koperasiId, string $koperasiSlug): void
    {
        $this->koperasiId = $koperasiId;
        $this->koperasiSlug = $koperasiSlug;

        $tz = (string) config('app.display_timezone', 'Asia/Jakarta');
        $this->startDate = now($tz)->startOfMonth()->format('Y-m-d');
        $this->endDate = now($tz)->format('Y-m-d');
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

    public function applyPreset(string $preset): void
    {
        $tz = (string) config('app.display_timezone', 'Asia/Jakarta');
        $today = now($tz);

        if ($preset === 'today') {
            $this->startDate = $today->format('Y-m-d');
            $this->endDate = $today->format('Y-m-d');
        } elseif ($preset === '7days') {
            $this->startDate = $today->copy()->subDays(6)->format('Y-m-d');
            $this->endDate = $today->format('Y-m-d');
        } else {
            $this->startDate = $today->copy()->startOfMonth()->format('Y-m-d');
            $this->endDate = $today->format('Y-m-d');
        }

        $this->resetErrorBag('dateRange');
        unset($this->report);
    }

    public function updatedStartDate(): void
    {
        $this->normalizeDateRange();
        unset($this->report);
    }

    public function updatedEndDate(): void
    {
        $this->normalizeDateRange();
        unset($this->report);
    }

    public function updatedChannel(): void
    {
        if (! in_array($this->channel, ['all', 'pos', 'online'], true)) {
            $this->channel = 'all';
        }

        unset($this->report);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function report(): array
    {
        [$start, $end] = $this->resolvedDates();
        $koperasi = app(KoperasiContext::class)->koperasi();

        return app(KoperasiSalesReportService::class)->build(
            koperasi: $koperasi,
            startDate: $start,
            endDate: $end,
            channel: $this->channel,
        );
    }

    /**
     * @return array{koperasi: string, start: string, end: string, channel: string}
     */
    #[Computed]
    public function exportParams(): array
    {
        [$start, $end] = $this->resolvedDates();

        return [
            'koperasi' => $this->koperasiSlug,
            'start' => $start,
            'end' => $end,
            'channel' => $this->channel,
        ];
    }

    private function normalizeDateRange(): void
    {
        $this->resetErrorBag('dateRange');

        if (! $this->isValidDate($this->startDate) || ! $this->isValidDate($this->endDate)) {
            $this->addError('dateRange', 'Format tanggal harus YYYY-MM-DD.');

            return;
        }

        if ($this->endDate < $this->startDate) {
            $this->endDate = $this->startDate;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolvedDates(): array
    {
        $tz = (string) config('app.display_timezone', 'Asia/Jakarta');
        $today = now($tz)->format('Y-m-d');

        $start = $this->isValidDate($this->startDate) ? $this->startDate : now($tz)->startOfMonth()->format('Y-m-d');
        $end = $this->isValidDate($this->endDate) ? $this->endDate : $today;

        if ($end < $start) {
            $end = $start;
        }

        return [$start, $end];
    }

    private function isValidDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d') === $value;
        } catch (\Throwable) {
            return false;
        }
    }
};
?>

@php
    $summary = $this->report['summary'];
    $topProducts = $this->report['top_products'];
    $transactions = $this->report['transactions'];
@endphp

<div class="space-y-6">
    @error('dateRange')
        <div class="rounded-lg bg-red-100 px-4 py-3 text-sm font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300" role="alert">
            {{ $message }}
        </div>
    @enderror

    {{-- Filter Periode, Kanal, & Tombol Ekspor --}}
    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid flex-1 grid-cols-1 gap-3 sm:grid-cols-3">
                <x-input name="startDate" label="Tanggal Mulai (WIB)" type="date" wire:model.live="startDate" />
                <x-input name="endDate" label="Tanggal Akhir (WIB)" type="date" wire:model.live="endDate" />
                <div class="flex flex-col gap-1">
                    <label for="channel" class="text-sm font-medium text-slate-700 dark:text-slate-200">Kanal Penjualan</label>
                    <select
                        id="channel"
                        wire:model.live="channel"
                        class="min-h-11 rounded-lg border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-800"
                    >
                        <option value="all">Semua Kanal (POS + Online)</option>
                        <option value="pos">POS Kasir Saja</option>
                        <option value="online">Pesanan Online Saja</option>
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <a
                    href="{{ route('koperasi.reports.export.csv', $this->exportParams) }}"
                    class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 transition hover:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                >
                    Ekspor CSV (Excel)
                </a>
                <a
                    href="{{ route('koperasi.reports.export.pdf', $this->exportParams) }}"
                    target="_blank"
                    class="inline-flex min-h-11 items-center justify-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white transition hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200"
                >
                    Cetak / PDF
                </a>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            <button
                type="button"
                wire:click="applyPreset('today')"
                class="min-h-11 rounded-lg border border-slate-300 px-3 text-xs font-semibold hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
            >
                Hari Ini
            </button>
            <button
                type="button"
                wire:click="applyPreset('7days')"
                class="min-h-11 rounded-lg border border-slate-300 px-3 text-xs font-semibold hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
            >
                7 Hari Terakhir
            </button>
            <button
                type="button"
                wire:click="applyPreset('month')"
                class="min-h-11 rounded-lg border border-slate-300 px-3 text-xs font-semibold hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
            >
                Bulan Ini
            </button>
        </div>
    </section>

    {{-- Kartu Ringkasan --}}
    <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Omzet</p>
            <p class="mt-2 text-2xl font-extrabold" data-test="report-total-sales">
                Rp {{ number_format((int) $summary['total_sales'], 0, ',', '.') }}
            </p>
            <p class="mt-1 text-xs text-slate-500">{{ $summary['total_transactions'] }} transaksi sah</p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Penjualan POS</p>
            <p class="mt-2 text-2xl font-extrabold">
                Rp {{ number_format((int) $summary['pos_sales'], 0, ',', '.') }}
            </p>
            <p class="mt-1 text-xs text-slate-500">{{ $summary['pos_transactions'] }} transaksi POS</p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Laba Kotor POS</p>
            <p class="mt-2 text-2xl font-extrabold text-emerald-700 dark:text-emerald-400">
                Rp {{ number_format((int) $summary['pos_gross_profit'], 0, ',', '.') }}
            </p>
            <p class="mt-1 text-xs text-slate-500">HPP: Rp {{ number_format((int) $summary['pos_cogs'], 0, ',', '.') }}</p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Penjualan Online</p>
            <p class="mt-2 text-2xl font-extrabold">
                Rp {{ number_format((int) $summary['online_sales'], 0, ',', '.') }}
            </p>
            <p class="mt-1 text-xs text-slate-500">{{ $summary['online_transactions'] }} pesanan online</p>
        </div>
    </section>

    {{-- Produk Terlaris --}}
    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-bold">10 Produk Terlaris</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full min-w-[30rem] text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-slate-800">
                    <tr>
                        <th class="py-2 pe-4">Produk</th>
                        <th class="py-2 pe-4 text-right">Qty Terjual</th>
                        <th class="py-2 text-right">Total Pendapatan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($topProducts as $item)
                        <tr>
                            <td class="py-2.5 pe-4 font-medium">{{ $item['product_name'] }}</td>
                            <td class="py-2.5 pe-4 text-right font-semibold">{{ $item['quantity'] }}</td>
                            <td class="py-2.5 text-right font-bold">Rp {{ number_format((int) $item['revenue'], 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-6 text-center text-slate-400">Belum ada penjualan pada periode ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Rincian Transaksi --}}
    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-bold">Rincian Transaksi ({{ count($transactions) }})</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full min-w-[46rem] text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-slate-800">
                    <tr>
                        <th class="py-2 pe-3">Tanggal (WIB)</th>
                        <th class="py-2 pe-3">Referensi</th>
                        <th class="py-2 pe-3">Kanal</th>
                        <th class="py-2 pe-3">Metode</th>
                        <th class="py-2 pe-3">Status</th>
                        <th class="py-2 pe-3">Kasir / Pemesan</th>
                        <th class="py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($transactions as $row)
                        <tr>
                            <td class="py-2.5 pe-3 text-xs text-slate-500">{{ $row['date'] }}</td>
                            <td class="py-2.5 pe-3 font-mono text-xs font-semibold">{{ $row['reference'] }}</td>
                            <td class="py-2.5 pe-3">
                                <x-status-badge :status="$row['channel'] === 'POS' ? 'default' : 'active'">
                                    {{ $row['channel'] }}
                                </x-status-badge>
                            </td>
                            <td class="py-2.5 pe-3">{{ $row['payment_method'] }}</td>
                            <td class="py-2.5 pe-3 uppercase text-xs">{{ $row['status'] }}</td>
                            <td class="py-2.5 pe-3">{{ $row['actor_name'] }}</td>
                            <td class="py-2.5 text-right font-bold">Rp {{ number_format((int) $row['total_amount'], 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6">
                                <x-empty-state
                                    title="Tidak ada transaksi pada periode ini"
                                    description="Ubah rentang tanggal atau filter kanal untuk melihat data lainnya."
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>