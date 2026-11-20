<x-layouts.koperasi :koperasi="$koperasi" title="Dashboard">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ $koperasi->name }}</h1>
            <p class="text-sm text-slate-500">
                Jam operasional: {{ substr((string) $koperasi->open_time, 0, 5) }} – {{ substr((string) $koperasi->close_time, 0, 5) }} WIB
            </p>
        </div>
        <x-status-badge :status="$koperasi->status">
            {{ $koperasi->isActive() ? 'Aktif' : 'Nonaktif' }}
        </x-status-badge>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Produk</p>
            <p class="mt-2 text-3xl font-bold">{{ \App\Models\Product::query()->count() }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pesanan Online Aktif</p>
            <p class="mt-2 text-3xl font-bold">{{ \App\Models\Order::query()->whereIn('status', ['paid', 'processing', 'ready'])->count() }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Shift Kasir Terbuka</p>
            <p class="mt-2 text-3xl font-bold">{{ \App\Models\CashierShift::query()->where('status', 'open')->count() }}</p>
        </div>
    </div>
</x-layouts.koperasi>