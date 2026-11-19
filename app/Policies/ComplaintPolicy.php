<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\Customer;
use App\Models\User;
use App\Support\Tenancy\KoperasiContext;
use Illuminate\Foundation\Auth\User as Authenticatable;

class ComplaintPolicy
{
    public function view(Authenticatable $actor, Complaint $complaint): bool
    {
        if ($actor instanceof Customer) {
            return (int) $complaint->customer_id === (int) $actor->id;
        }

        if ($actor instanceof User && $actor->isAdminKoperasi()) {
            return (int) $complaint->koperasi_id === app(KoperasiContext::class)->idOrNull();
        }

        return false;
    }

    public function respond(User $user, Complaint $complaint): bool
    {
        return $user->isAdminKoperasi()
            && (int) $complaint->koperasi_id === app(KoperasiContext::class)->idOrNull();
    }
}
