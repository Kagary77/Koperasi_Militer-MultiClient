<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Struk {{ $sale->invoice_number }}</title>
    <style>
        body { font-family: monospace; font-size: 12px; color: #111; margin: 0; padding: 12px; }
        .receipt { max-width: 78mm; margin: 0 auto; }
        .center { text-align: center; }
        .line { border-top: 1px dashed #333; margin: 8px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .bold { font-weight: bold; }
        @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="center">
            <div class="bold">{{ strtoupper($koperasi->name) }}</div>
            <div>{{ $koperasi->address }}</div>
            <div>Telp: {{ $koperasi->phone }}</div>
        </div>
        <div class="line"></div>
        <div class="row"><span>No:</span><span>{{ $sale->invoice_number }}</span></div>
        <div class="row"><span>Tgl:</span><span>{{ $sale->created_at->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i') }}</span></div>
        <div class="row"><span>Kasir:</span><span>{{ $sale->user?->name }}</span></div>
        <div class="row"><span>Status:</span><span class="bold">{{ strtoupper($sale->status) }}</span></div>
        <div class="line"></div>
        @foreach ($sale->items as $item)
            <div>{{ $item->product_name }}</div>
            <div class="row">
                <span>{{ $item->quantity }} x Rp {{ number_format((int) $item->price_snapshot, 0, ',', '.') }}</span>
                <span>Rp {{ number_format((int) $item->subtotal, 0, ',', '.') }}</span>
            </div>
        @endforeach
        <div class="line"></div>
        <div class="row bold">
            <span>TOTAL</span>
            <span>Rp {{ number_format((int) $sale->total_amount, 0, ',', '.') }}</span>
        </div>
        <div class="row">
            <span>Metode</span>
            <span>{{ strtoupper($sale->payment_method) }}</span>
        </div>
        @if ($sale->payment_method === 'cash')
            <div class="row">
                <span>Bayar</span>
                <span>Rp {{ number_format((int) $sale->amount_paid, 0, ',', '.') }}</span>
            </div>
            <div class="row">
                <span>Kembali</span>
                <span>Rp {{ number_format((int) $sale->change_amount, 0, ',', '.') }}</span>
            </div>
        @endif
        <div class="line"></div>
        <div class="center">Terima kasih atas kunjungan Anda</div>
        <div class="center no-print" style="margin-top: 14px;">
            <button type="button" onclick="window.print()" style="padding: 8px 16px; cursor: pointer;">Cetak Struk</button>
        </div>
    </div>
</body>
</html>