<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Penjualan — {{ $koperasi->name }}</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; color: #0f172a; margin: 0; padding: 24px; font-size: 12px; }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .meta { color: #475569; margin-bottom: 16px; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
        .card { border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; }
        .card-label { font-size: 10px; text-transform: uppercase; color: #64748b; }
        .card-value { font-size: 16px; font-weight: 700; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        th { background: #f1f5f9; font-size: 11px; text-transform: uppercase; }
        .right { text-align: right; }
        .no-print { margin-bottom: 16px; }
        @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="no-print">
            <button type="button" onclick="window.print()" style="padding: 8px 16px; font-weight: 600; cursor: pointer;">
                Cetak / Simpan sebagai PDF
            </button>
        </div>

        <h1>Laporan Penjualan — {{ $koperasi->name }}</h1>
        <p class="meta">
            Periode: {{ $report['period']['start'] }} s/d {{ $report['period']['end'] }} ·
            Kanal: {{ strtoupper($report['period']['channel']) }} ·
            Dicetak: {{ now(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB
        </p>

        <div class="grid">
            <div class="card">
                <div class="card-label">Total Omzet</div>
                <div class="card-value">Rp {{ number_format($report['summary']['total_sales'], 0, ',', '.') }}</div>
            </div>
            <div class="card">
                <div class="card-label">Total Transaksi</div>
                <div class="card-value">{{ $report['summary']['total_transactions'] }}</div>
            </div>
            <div class="card">
                <div class="card-label">Omzet POS</div>
                <div class="card-value">Rp {{ number_format($report['summary']['pos_sales'], 0, ',', '.') }}</div>
            </div>
            <div class="card">
                <div class="card-label">Omzet Online</div>
                <div class="card-value">Rp {{ number_format($report['summary']['online_sales'], 0, ',', '.') }}</div>
            </div>
        </div>

        <h2 style="font-size: 14px; margin: 16px 0 6px;">Rincian Transaksi</h2>
        <table>
            <thead>
                <tr>
                    <th>Tanggal (WIB)</th>
                    <th>Referensi</th>
                    <th>Kanal</th>
                    <th>Metode</th>
                    <th>Status</th>
                    <th>Kasir / Pemesan</th>
                    <th class="right">Total (Rp)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['transactions'] as $row)
                    <tr>
                        <td>{{ $row['date'] }}</td>
                        <td>{{ $row['reference'] }}</td>
                        <td>{{ $row['channel'] }}</td>
                        <td>{{ $row['payment_method'] }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td>{{ $row['actor_name'] }}</td>
                        <td class="right">{{ number_format($row['total_amount'], 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; color: #64748b;">Tidak ada transaksi pada periode ini.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>