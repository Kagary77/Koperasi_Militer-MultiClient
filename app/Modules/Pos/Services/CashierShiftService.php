<?php

namespace App\Modules\Pos\Services;

use App\Models\CashierShift;
use App\Models\Koperasi;
use App\Models\Sale;
use App\Models\User;
use App\Modules\Pos\Exceptions\PosException;
use App\Services\Shared\AuditLogger;
use Illuminate\Support\Facades\DB;

final class CashierShiftService
{
    public function __construct(private AuditLogger $audit) {}

    public function activeShift(Koperasi $koperasi, User $user): ?CashierShift
    {
        return CashierShift::query()
            ->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $koperasi->id)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->latest('id')
            ->first();
    }

    /**
     * Membuka shift kasir baru dengan modal awal (UC-14 langkah 1).
     *
     * @throws PosException
     */
    public function open(Koperasi $koperasi, User $user, int $openingBalance): CashierShift
    {
        if ($openingBalance < 0) {
            throw PosException::invalidOpeningBalance();
        }

        return DB::transaction(function () use ($koperasi, $user, $openingBalance): CashierShift {
            $existing = CashierShift::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $koperasi->id)
                ->where('user_id', $user->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw PosException::shiftAlreadyOpen();
            }

            $shift = new CashierShift;
            $shift->forceFill([
                'koperasi_id' => $koperasi->id,
                'user_id' => $user->id,
                'start_time' => now(),
                'end_time' => null,
                'opening_balance' => $openingBalance,
                'expected_cash' => null,
                'actual_cash' => null,
                'difference' => null,
                'status' => 'open',
            ])->save();

            $this->audit->record(
                action: 'shift_opened',
                modelType: CashierShift::class,
                modelId: $shift->id,
                oldValues: null,
                newValues: ['opening_balance' => $openingBalance, 'status' => 'open'],
                koperasiId: (int) $koperasi->id,
                userId: (int) $user->id,
            );

            return $shift;
        });
    }

    /**
     * Menutup shift kasir dan menghitung rekap kas fisik terhadap sistem (UC-14 langkah 6).
     *
     * @throws PosException
     */
    public function close(Koperasi $koperasi, CashierShift $shift, User $actor, int $actualCash): CashierShift
    {
        if ($actualCash < 0) {
            throw PosException::invalidActualCash();
        }

        return DB::transaction(function () use ($koperasi, $shift, $actor, $actualCash): CashierShift {
            $locked = CashierShift::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $koperasi->id)
                ->lockForUpdate()
                ->findOrFail($shift->id);

            if (! $locked->isOpen()) {
                throw PosException::shiftNotOpen();
            }

            if ((int) $locked->user_id !== (int) $actor->id && ! $actor->isAdminKoperasi()) {
                throw PosException::unauthorizedAction('Anda tidak berhak menutup shift milik kasir lain.');
            }

            // Hanya penjualan tunai sah (paid / void_requested) yang masuk perhitungan kas laci.
            $cashSalesTotal = (int) Sale::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $koperasi->id)
                ->where('shift_id', $locked->id)
                ->where('payment_method', 'cash')
                ->whereIn('status', ['paid', 'void_requested'])
                ->sum('total_amount');

            $expectedCash = (int) $locked->opening_balance + $cashSalesTotal;
            $difference = $actualCash - $expectedCash;

            $locked->forceFill([
                'end_time' => now(),
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash,
                'difference' => $difference,
                'status' => 'closed',
            ])->save();

            $this->audit->record(
                action: 'shift_closed',
                modelType: CashierShift::class,
                modelId: $locked->id,
                oldValues: ['status' => 'open'],
                newValues: [
                    'status' => 'closed',
                    'opening_balance' => (int) $locked->opening_balance,
                    'cash_sales_total' => $cashSalesTotal,
                    'expected_cash' => $expectedCash,
                    'actual_cash' => $actualCash,
                    'difference' => $difference,
                ],
                koperasiId: (int) $koperasi->id,
                userId: (int) $actor->id,
            );

            return $locked;
        });
    }
}
