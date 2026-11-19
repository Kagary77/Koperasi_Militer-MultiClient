<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Support\Tenancy\KoperasiContext;

class ProductPolicy
{
    private function ownedByActiveKoperasi(Product $product): bool
    {
        return (int) $product->koperasi_id === app(KoperasiContext::class)->idOrNull();
    }

    public function view(User $user, Product $product): bool
    {
        return ($user->isAdminKoperasi() || $user->isKasir())
            && $this->ownedByActiveKoperasi($product);
    }

    public function create(User $user): bool
    {
        return $user->isAdminKoperasi() && app(KoperasiContext::class)->has();
    }

    public function update(User $user, Product $product): bool
    {
        return $user->isAdminKoperasi() && $this->ownedByActiveKoperasi($product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->isAdminKoperasi() && $this->ownedByActiveKoperasi($product);
    }

    public function toggleAvailability(User $user, Product $product): bool
    {
        return ($user->isAdminKoperasi() || $user->isKasir())
            && $this->ownedByActiveKoperasi($product);
    }
}
