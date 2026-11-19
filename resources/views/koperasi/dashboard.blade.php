<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
    <title>Dashboard — {{ $koperasi->name }}</title>
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
    <main class="mx-auto max-w-6xl p-6">
        <h1 class="text-2xl font-bold">Dashboard Koperasi — {{ $koperasi->name }}</h1>
        <p class="mt-1 text-sm text-zinc-500">Slug: <span class="font-mono">{{ $koperasi->slug }}</span> · Status: {{ $koperasi->status }}</p>
    </main>
</body>
</html>