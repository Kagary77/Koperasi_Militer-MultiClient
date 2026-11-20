@props(['koperasi', 'title' => 'Dashboard'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="antialiased">
<head>
    @include('partials.head')
    <title>{{ $title }} — {{ $koperasi->name }}</title>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    @php($user = auth('web')->user())
    <div x-data="{ open: false }" class="flex min-h-screen">
        <aside
            :class="open ? 'translate-x-0' : '-translate-x-full'"
            class="fixed inset-y-0 left-0 z-20 flex w-64 transform flex-col border-r border-slate-200 bg-white p-4 transition-transform duration-200 lg:static lg:translate-x-0 dark:border-slate-800 dark:bg-slate-900"
        >
            <div class="mb-6 border-b border-slate-200 pb-4 dark:border-slate-800">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Portal Koperasi</p>
                <p class="mt-1 truncate text-base font-bold text-slate-900 dark:text-white">{{ $koperasi->name }}</p>
            </div>

            <nav class="flex flex-1 flex-col gap-1 text-sm">
                <a
                    href="{{ route('koperasi.dashboard', ['koperasi' => $koperasi->slug]) }}"
                    @class([
                        'flex min-h-11 items-center rounded-lg px-3 font-medium transition',
                        'bg-slate-900 text-white dark:bg-white dark:text-slate-900' => request()->routeIs('koperasi.dashboard'),
                        'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800' => ! request()->routeIs('koperasi.dashboard'),
                    ])
                >
                    Dashboard
                </a>

                <span class="flex min-h-11 items-center px-3 text-slate-400">POS &amp; Shift Kasir (Sprint 2)</span>
                <span class="flex min-h-11 items-center px-3 text-slate-400">Pesanan Online (Sprint 3)</span>

                @if ($user?->isAdminKoperasi())
                    <span class="mt-3 px-3 text-xs font-semibold uppercase tracking-wider text-slate-400">Manajemen</span>
                    <span class="flex min-h-11 items-center px-3 text-slate-400">Produk &amp; Stok (Tahap 1.3)</span>
                    <span class="flex min-h-11 items-center px-3 text-slate-400">Karyawan (Tahap 1.2)</span>
                    <span class="flex min-h-11 items-center px-3 text-slate-400">Pengaduan (Sprint 3)</span>
                    <span class="flex min-h-11 items-center px-3 text-slate-400">Laporan Penjualan (Sprint 3)</span>
                @endif
            </nav>

            <div class="mt-4 border-t border-slate-200 pt-4 text-xs dark:border-slate-800">
                <p class="font-semibold text-slate-800 dark:text-slate-100">{{ $user?->name }}</p>
                <p class="text-slate-500">{{ $user?->role === 'admin_koperasi' ? 'Admin Koperasi' : 'Kasir' }}</p>
                <form method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf
                    <button
                        type="submit"
                        class="flex min-h-11 w-full items-center justify-center rounded-lg border border-slate-300 px-3 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        Keluar
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex min-h-16 items-center gap-3 border-b border-slate-200 bg-white px-4 lg:px-6 dark:border-slate-800 dark:bg-slate-900">
                <button
                    type="button"
                    @click="open = !open"
                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-slate-200 lg:hidden dark:border-slate-700"
                    aria-label="Toggle navigasi"
                >
                    ☰
                </button>
                <span class="text-base font-semibold">{{ $title }}</span>
                @isset($headerRight)
                    <div class="ms-auto">{{ $headerRight }}</div>
                @endisset
            </header>

            <main class="flex-1 p-4 lg:p-6">
                @if (session('status'))
                    <div class="mb-4 rounded-lg bg-emerald-100 px-4 py-3 text-sm font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                        {{ session('status') }}
                    </div>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>
    @fluxScripts
</body>
</html>