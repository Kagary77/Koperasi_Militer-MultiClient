<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Modules\Payments\Services\PaymentWebhookService;
use Illuminate\Console\Command;

class ExpirePendingPaymentsCommand extends Command
{
    protected $signature = 'payments:expire-pending';

    protected $description = 'Membatalkan otomatis tagihan QRIS pending yang melewati batas waktu 15 menit beserta transaksi terkait.';

    public function handle(PaymentWebhookService $service): int
    {
        $expiredIds = Payment::query()
            ->where('status', 'pending')
            ->where('expired_at', '<=', now())
            ->orderBy('id')
            ->pluck('id');

        $count = 0;

        foreach ($expiredIds as $paymentId) {
            if ($service->expirePendingPayment((int) $paymentId)) {
                $count++;
            }
        }

        $this->info("Tagihan QRIS kedaluwarsa diproses: {$count}");

        return self::SUCCESS;
    }
}
