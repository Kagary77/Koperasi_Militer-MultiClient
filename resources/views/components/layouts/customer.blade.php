@props(['title' => 'Portal Anggota Koperasi', 'koperasi' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="antialiased">
<head>
    @include('partials.head')
    <title>{{ $title }} — Koperasi Militer</title>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    @php
        $customer = auth('customer')->user();
        $unreadCount = $customer ? $customer->unreadNotifications()->count() : 0;
    @endphp

    <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
            <a href="{{ route('customer.landing') }}" class="flex min-h-11 items-center gap-2 font-bold text-slate-900 dark:text-white">
                <span class="rounded-lg bg-slate-900 px-2.5 py-1 text-xs font-extrabold tracking-wide text-white dark:bg-white dark:text-slate-900">
                    KOPMIL
                </span>
                <span class="truncate text-sm sm:text-base">
                    {{ $koperasi ? $koperasi->name : 'Portal Anggota Koperasi' }}
                </span>
            </a>

            <nav class="flex flex-wrap items-center gap-2 text-sm">
                <a
                    href="{{ route('customer.landing') }}"
                    class="inline-flex min-h-11 items-center rounded-lg px-3 font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    Pilih Koperasi
                </a>

                @if ($customer)
                    <a
                        href="{{ route('customer.notifications') }}"
                        class="inline-flex min-h-11 items-center gap-1.5 rounded-lg px-3 font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        <span>Notifikasi</span>
                        @if ($unreadCount > 0)
                            <span class="rounded-full bg-red-600 px-2 py-0.5 text-xs font-bold text-white">
                                {{ $unreadCount }}
                            </span>
                        @endif
                    </a>

                    <span class="hidden text-xs text-slate-500 sm:inline">{{ $customer->name }}</span>

                    <form method="POST" action="{{ route('customer.logout') }}">
                        @csrf
                        <button
                            type="submit"
                            class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-3 font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        >
                            Keluar
                        </button>
                    </form>
                @else
                    <a
                        href="{{ route('customer.login') }}"
                        class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-3 font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        Masuk
                    </a>
                    <a
                        href="{{ route('customer.register') }}"
                        class="inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-3 font-semibold text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900"
                    >
                        Daftar
                    </a>
                @endif
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
        @if (session('status'))
            <div class="mb-5 rounded-lg bg-emerald-100 px-4 py-3 text-sm font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                {{ session('status') }}
            </div>
        @endif

        {{ $slot }}
    </main>

    @fluxScripts
</body>
</html>