@props(['title' => 'Belum ada data', 'description' => null])

<div {{ $attributes->class('flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 p-10 text-center dark:border-slate-700') }}>
    <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $title }}</p>
    @if ($description)
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $description }}</p>
    @endif
    @isset($action)
        <div class="mt-2">{{ $action }}</div>
    @endisset
</div>