<?php

namespace App\Modules\Reporting\Services;

use App\Models\Koperasi;
use App\Models\Order;
use App\Models\ReportSnapshot;
use App\Models\Sale;
use Carbon\Carbon;

final class DailyReportSnapshotService
{
    public function generateForKoperasi(Koperasi $koperasi, string $date): ReportSnapshot
    {
        $tz = (string) config('app.display_timezone', 'Asia/Jakarta');
        $startUtc = Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay()->utc();
        $endUtc = Carbon::createFromFormat('Y-m-d', $date, $tz)->endOfDay()->utc();

        $posQuery = Sale::query()
            ->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $koperasi->id)
            ->whereIn('status', KoperasiSalesReportService::VALID_POS_STATUSES)
            ->whereBetween('created_at', [$startUtc, $endUtc]);

        $posTotal = (int) (clone $posQuery)->sum('total_amount');
        $posCount = (clone $posQuery)->count();

        $onlineQuery = Order::query()
            ->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $koperasi->id)
            ->whereIn('status', KoperasiSalesReportService::VALID_ONLINE_STATUSES)
            ->whereBetween('created_at', [$startUtc, $endUtc]);

        $onlineTotal = (int) (clone $onlineQuery)->sum('total_amount');
        $onlineCount = (clone $onlineQuery)->count();
        $activeCustomers = (int) (clone $onlineQuery)->distinct('customer_id')->count('customer_id');

        $snapshot = ReportSnapshot::query()
            ->where('koperasi_id', $koperasi->id)
            ->whereDate('date', $date)
            ->first() ?? new ReportSnapshot;

        $snapshot->forceFill([
            'koperasi_id' => $koperasi->id,
            'date' => $date,
            'total_sales' => $posTotal + $onlineTotal,
            'total_transactions' => $posCount + $onlineCount,
            'total_online_orders' => $onlineCount,
            'total_pos_orders' => $posCount,
            'total_active_customers' => $activeCustomers,
            'created_at' => $snapshot->created_at ?? now(),
        ])->save();

        return $snapshot;
    }

    public function generateForAllActive(string $date): int
    {
        $count = 0;

        Koperasi::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (Koperasi $koperasi) use ($date, &$count): void {
                $this->generateForKoperasi($koperasi, $date);
                $count++;
            });

        return $count;
    }
}
