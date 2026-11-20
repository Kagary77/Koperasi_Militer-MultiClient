@props(['status' => 'default'])

@php
$map = [
    'active' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
    'pending' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    'inactive' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
    'default' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
];
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold', $map[$status] ?? $map['default']]) }}>
    {{ $slot }}
</span>