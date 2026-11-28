<?php

namespace App\Console\Commands;

use App\Modules\Reporting\Services\DailyReportSnapshotService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateDailyReportSnapshotsCommand extends Command
{
    protected $signature = 'reports:snapshot-daily {--date= : Tanggal target format Y-m-d (default: kemarin WIB)}';

    protected $description = 'Membangkitkan snapshot laporan penjualan harian secara idempoten untuk seluruh koperasi aktif.';

    public function handle(DailyReportSnapshotService $service): int
    {
        $tz = (string) config('app.display_timezone', 'Asia/Jakarta');
        $dateOption = $this->option('date');

        $date = is_string($dateOption) && $dateOption !== ''
            ? Carbon::createFromFormat('Y-m-d', $dateOption, $tz)->format('Y-m-d')
            : now($tz)->subDay()->format('Y-m-d');

        $count = $service->generateForAllActive($date);

        $this->info("Snapshot harian {$date} selesai dibuat untuk {$count} koperasi aktif.");

        return self::SUCCESS;
    }
}
