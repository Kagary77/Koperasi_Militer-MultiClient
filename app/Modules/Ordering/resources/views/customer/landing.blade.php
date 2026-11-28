<x-layouts.customer title="Pilih Koperasi">
    <section class="rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="max-w-2xl">
            <h1 class="text-2xl font-extrabold tracking-tight">Belanja Sembako Koperasi Satuan</h1>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                Pilih koperasi aktif di bawah ini untuk melihat katalog produk, memesan secara online dengan QRIS dinamis, dan mengambil pesanan di loket koperasi.
            </p>
        </div>

        <form method="GET" action="{{ route('customer.landing') }}" class="mt-4 flex flex-wrap gap-2">
            <input
                type="search"
                name="q"
                value="{{ $search }}"
                placeholder="Cari nama koperasi atau lokasi satuan…"
                class="min-h-11 min-w-[16rem] flex-1 rounded-lg border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-800"
            />
            <x-button type="submit">Cari Koperasi</x-button>
            @if ($search !== '')
                <a
                    href="{{ route('customer.landing') }}"
                    class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200"
                >
                    Reset
                </a>
            @endif
        </form>
    </section>

    <section class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($koperasis as $koperasi)
            <article class="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div>
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="text-base font-bold">{{ $koperasi->name }}</h2>
                        <x-status-badge status="active">Aktif</x-status-badge>
                    </div>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $koperasi->address }}</p>
                    @if ($koperasi->phone)
                        <p class="mt-1 font-mono text-xs text-slate-500">Telp: {{ $koperasi->phone }}</p>
                    @endif
                </div>

                <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800">
                    @if (Route::has('customer.catalog.index'))
                        <a
                            href="{{ route('customer.catalog.index', ['koperasi' => $koperasi->slug]) }}"
                            class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900"
                        >
                            Lihat Katalog Produk
                        </a>
                    @else
                        <span class="inline-flex min-h-11 w-full items-center justify-center rounded-lg border border-slate-200 px-4 text-xs font-medium text-slate-500 dark:border-slate-700">
                            Katalog Produk (Tahap P2)
                        </span>
                    @endif
                </div>
            </article>
        @empty
            <div class="col-span-full">
                <x-empty-state
                    title="Koperasi tidak ditemukan"
                    description="Tidak ada koperasi aktif yang cocok dengan pencarian Anda."
                />
            </div>
        @endforelse
    </section>
</x-layouts.customer>