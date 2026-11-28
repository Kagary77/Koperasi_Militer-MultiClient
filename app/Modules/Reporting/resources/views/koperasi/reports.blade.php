<x-layouts.koperasi :koperasi="$koperasi" title="Laporan Penjualan">
    <livewire:reporting::sales-report :koperasi-id="$koperasi->id" :koperasi-slug="$koperasi->slug" />
</x-layouts.koperasi>