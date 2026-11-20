@props(['type' => 'button', 'variant' => 'primary'])

@php
$variants = [
    'primary' => 'bg-slate-900 text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200',
    'secondary' => 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700',
    'danger' => 'bg-red-600 text-white hover:bg-red-700',
];
@endphp

<button
    type="{{ $type }}"
    {{ $attributes->class([
        'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 disabled:cursor-not-allowed disabled:opacity-50',
        $variants[$variant] ?? $variants['primary'],
    ]) }}
>
    {{ $slot }}
</button>