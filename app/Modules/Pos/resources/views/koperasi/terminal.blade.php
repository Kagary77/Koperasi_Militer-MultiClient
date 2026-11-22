<x-layouts.koperasi :koperasi="$koperasi" title="POS &amp; Shift Kasir">
    <livewire:pos::terminal :koperasi-id="$koperasi->id" :koperasi-slug="$koperasi->slug" />
</x-layouts.koperasi>