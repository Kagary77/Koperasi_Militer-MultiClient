<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;
use App\Support\Tenancy\KoperasiContext;

class SalePolicy
{
    private function ownedByActiveKoperasi(Sale $sale): bool
    {
        return (int) $sale->koperasi_id === app(KoperasiContext::class)->idOrNull();
    }

    public function view(User $user, Sale $sale): bool
    {
        return ($user->isAdminKoperasi() || $user->isKasir())
            && $this->ownedByActiveKoperasi($sale);
    }

    public function requestVoid(User $user, Sale $sale): bool
    {
        return $user->isKasir()
            && $this->ownedByActiveKoperasi($sale)
            && $sale->status === 'paid';
    }

    public function reviewVoid(User $user, Sale $sale): bool
    {
        return $user->isAdminKoperasi()
            && $this->ownedByActiveKoperasi($sale)
            && $sale->status === 'void_requested';
    }

    public function markRefunded(User $user, Sale $sale): bool
    {
        return $user->isAdminKoperasi()
            && $this->ownedByActiveKoperasi($sale)
            && $sale->status === 'voided';
    }
}
