<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Support\Tenancy\KoperasiContext;
use Illuminate\Foundation\Auth\User as Authenticatable;

class OrderPolicy
{
    public function view(Authenticatable $actor, Order $order): bool
    {
        if ($actor instanceof Customer) {
            return (int) $order->customer_id === (int) $actor->id;
        }

        if ($actor instanceof User && ($actor->isAdminKoperasi() || $actor->isKasir())) {
            return (int) $order->koperasi_id === app(KoperasiContext::class)->idOrNull();
        }

        return false;
    }

    public function updateStatus(User $user, Order $order): bool
    {
        return ($user->isAdminKoperasi() || $user->isKasir())
            && (int) $order->koperasi_id === app(KoperasiContext::class)->idOrNull();
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->isAdminKoperasi()
            && (int) $order->koperasi_id === app(KoperasiContext::class)->idOrNull()
            && in_array($order->status, ['paid', 'processing'], true);
    }
}
