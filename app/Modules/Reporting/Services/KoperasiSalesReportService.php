<?php

namespace App\Modules\Reporting\Services;

use App\Models\Koperasi;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class KoperasiSalesReportService
{
    /** @var list<string> */
    public const VALID_POS_STATUSES = ['paid', 'void_requested'];

    /** @var list<string> */
    public const VALID_ONLINE_STATUSES = ['paid', 'processing', 'ready', 'completed'];

    /**
     * Menghasilkan data laporan penjualan terstruktur untuk satu koperasi pada rentang tanggal WIB.
     *
     * @return array{
     *     period: array{start: string, end: string, channel: string},
     *     summary: array{
     *         total_sales: int,
     *         total_transactions: int,
     *         pos_sales: int,
     *         pos_transactions: int,
     *         pos_cogs: int,
     *         pos_gross_profit: int,
     *         online_sales: int,
     *         online_transactions: int
     *     },
     *     top_products: list<array{product_name: string, quantity: int, revenue: int}>,
     *     transactions: list<array{
     *         date: string,
     *         reference: string,
     *         channel: string,
     *         payment_method: string,
     *         status: string,
     *         actor_name: string,
     *         total_amount: int
     *     }>
     * }
     */
    public function build(
        Koperasi $koperasi,
        string $startDate,
        string $endDate,
        string $channel = 'all',
    ): array {
        $tz = (string) config('app.display_timezone', 'Asia/Jakarta');
        $channel = in_array($channel, ['all', 'pos', 'online'], true) ? $channel : 'all';

        $startUtc = Carbon::createFromFormat('Y-m-d', $startDate, $tz)->startOfDay()->utc();
        $endUtc = Carbon::createFromFormat('Y-m-d', $endDate, $tz)->endOfDay()->utc();

        $includePos = in_array($channel, ['all', 'pos'], true);
        $includeOnline = in_array($channel, ['all', 'online'], true);

        $sales = $includePos
            ? Sale::query()
                ->withoutGlobalScope('koperasi')
                ->with(['user:id,name', 'items'])
                ->where('koperasi_id', $koperasi->id)
                ->whereIn('status', self::VALID_POS_STATUSES)
                ->whereBetween('created_at', [$startUtc, $endUtc])
                ->orderByDesc('created_at')
                ->get()
            : new Collection;

        $orders = $includeOnline
            ? Order::query()
                ->withoutGlobalScope('koperasi')
                ->with(['customer:id,name', 'items'])
                ->where('koperasi_id', $koperasi->id)
                ->whereIn('status', self::VALID_ONLINE_STATUSES)
                ->whereBetween('created_at', [$startUtc, $endUtc])
                ->orderByDesc('created_at')
                ->get()
            : new Collection;

        $posSales = (int) $sales->sum('total_amount');
        $posTransactions = $sales->count();
        $posCogs = 0;

        /** @var array<string, array{product_name: string, quantity: int, revenue: int}> $productTotals */
        $productTotals = [];
        $transactions = [];

        foreach ($sales as $sale) {
            foreach ($sale->items as $item) {
                /** @var SaleItem $item */
                $posCogs += (int) $item->cost_snapshot * (int) $item->quantity;
                $this->accumulateProduct($productTotals, (string) $item->product_name, (int) $item->quantity, (int) $item->subtotal);
            }

            $transactions[] = [
                'timestamp' => $sale->created_at->getTimestamp(),
                'date' => $sale->created_at->setTimezone($tz)->format('Y-m-d H:i'),
                'reference' => (string) $sale->invoice_number,
                'channel' => 'POS',
                'payment_method' => strtoupper((string) $sale->payment_method),
                'status' => (string) $sale->status,
                'actor_name' => (string) ($sale->user?->name ?? '-'),
                'total_amount' => (int) $sale->total_amount,
            ];
        }

        $onlineSales = (int) $orders->sum('total_amount');
        $onlineTransactions = $orders->count();

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                /** @var OrderItem $item */
                $this->accumulateProduct($productTotals, (string) $item->product_name, (int) $item->quantity, (int) $item->subtotal);
            }

            $transactions[] = [
                'timestamp' => $order->created_at->getTimestamp(),
                'date' => $order->created_at->setTimezone($tz)->format('Y-m-d H:i'),
                'reference' => (string) $order->order_number,
                'channel' => 'ONLINE',
                'payment_method' => 'QRIS',
                'status' => (string) $order->status,
                'actor_name' => (string) ($order->customer?->name ?? 'Anggota'),
                'total_amount' => (int) $order->total_amount,
            ];
        }

        usort($transactions, fn (array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
        $cleanTransactions = array_map(function (array $row): array {
            unset($row['timestamp']);

            return $row;
        }, $transactions);

        usort($productTotals, fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
                'channel' => $channel,
            ],
            'summary' => [
                'total_sales' => $posSales + $onlineSales,
                'total_transactions' => $posTransactions + $onlineTransactions,
                'pos_sales' => $posSales,
                'pos_transactions' => $posTransactions,
                'pos_cogs' => $posCogs,
                'pos_gross_profit' => $posSales - $posCogs,
                'online_sales' => $onlineSales,
                'online_transactions' => $onlineTransactions,
            ],
            'top_products' => array_slice(array_values($productTotals), 0, 10),
            'transactions' => $cleanTransactions,
        ];
    }

    /**
     * @param  array<string, array{product_name: string, quantity: int, revenue: int}>  $bucket
     */
    private function accumulateProduct(array &$bucket, string $name, int $qty, int $revenue): void
    {
        if (! isset($bucket[$name])) {
            $bucket[$name] = [
                'product_name' => $name,
                'quantity' => 0,
                'revenue' => 0,
            ];
        }

        $bucket[$name]['quantity'] += $qty;
        $bucket[$name]['revenue'] += $revenue;
    }
}
