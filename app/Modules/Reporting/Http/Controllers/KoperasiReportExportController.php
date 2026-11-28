<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Koperasi;
use App\Modules\Reporting\Services\KoperasiSalesReportService;
use App\Services\Shared\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class KoperasiReportExportController extends Controller
{
    public function __construct(
        private KoperasiSalesReportService $reports,
        private AuditLogger $audit,
    ) {}

    public function csv(Request $request, Koperasi $koperasi): StreamedResponse
    {
        [$start, $end, $channel] = $this->resolveFilter($request);
        $report = $this->reports->build($koperasi, $start, $end, $channel);

        $this->audit->record(
            action: 'report_exported_csv',
            modelType: Koperasi::class,
            modelId: $koperasi->id,
            oldValues: null,
            newValues: ['start' => $start, 'end' => $end, 'channel' => $channel],
            koperasiId: (int) $koperasi->id,
        );

        $filename = "laporan-penjualan-{$koperasi->slug}-{$start}-sd-{$end}.csv";

        return response()->streamDownload(function () use ($koperasi, $report): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            // UTF-8 BOM agar kolom dan karakter Rupiah terbuka rapi di Microsoft Excel Windows.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Laporan Penjualan Koperasi', $this->safeCell($koperasi->name)]);
            fputcsv($out, ['Periode', "{$report['period']['start']} s/d {$report['period']['end']}"]);
            fputcsv($out, ['Kanal', strtoupper($report['period']['channel'])]);
            fputcsv($out, ['Total Omzet (Rp)', $report['summary']['total_sales']]);
            fputcsv($out, ['Total Transaksi', $report['summary']['total_transactions']]);
            fputcsv($out, []);

            fputcsv($out, ['Tanggal (WIB)', 'No. Referensi', 'Kanal', 'Metode', 'Status', 'Kasir / Pemesan', 'Total (Rp)']);

            foreach ($report['transactions'] as $row) {
                fputcsv($out, [
                    $row['date'],
                    $this->safeCell($row['reference']),
                    $row['channel'],
                    $row['payment_method'],
                    $row['status'],
                    $this->safeCell($row['actor_name']),
                    $row['total_amount'],
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function printPdf(Request $request, Koperasi $koperasi): View
    {
        [$start, $end, $channel] = $this->resolveFilter($request);
        $report = $this->reports->build($koperasi, $start, $end, $channel);

        $this->audit->record(
            action: 'report_exported_pdf',
            modelType: Koperasi::class,
            modelId: $koperasi->id,
            oldValues: null,
            newValues: ['start' => $start, 'end' => $end, 'channel' => $channel],
            koperasiId: (int) $koperasi->id,
        );

        return view('reporting::koperasi.report-print', [
            'koperasi' => $koperasi,
            'report' => $report,
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveFilter(Request $request): array
    {
        $tz = (string) config('app.display_timezone', 'Asia/Jakarta');
        $today = now($tz)->format('Y-m-d');
        $defaultStart = now($tz)->startOfMonth()->format('Y-m-d');

        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
            'channel' => ['nullable', 'in:all,pos,online'],
        ]);

        return [
            (string) ($validated['start'] ?? $defaultStart),
            (string) ($validated['end'] ?? $today),
            (string) ($validated['channel'] ?? 'all'),
        ];
    }

    /**
     * Mencegah CSV Formula Injection saat berkas dibuka di Excel.
     */
    private function safeCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
