<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
    <title>Dashboard Koperasi</title>
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
    <main class="mx-auto max-w-6xl p-6">
        <h1 class="text-2xl font-bold">Dashboard Koperasi — {{ $koperasi }}</h1>
        <p class="mt-2 text-sm text-zinc-600">Modul operasional Koperasi (Produk, Stok, POS, Pesanan Online, Laporan).</p>
    </main>
</body>
</html>