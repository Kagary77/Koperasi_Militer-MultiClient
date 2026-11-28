<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Koperasi;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class CustomerPortalController extends Controller
{
    public function landing(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $koperasis = Koperasi::query()
            ->where('status', 'active')
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('address', 'like', $like));
            })
            ->orderBy('name')
            ->get();

        return view('ordering::customer.landing', [
            'koperasis' => $koperasis,
            'search' => $search,
        ]);
    }

    public function notifications(): View
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        $notifications = $customer->notifications()
            ->latest()
            ->limit(40)
            ->get();

        return view('ordering::customer.notifications', [
            'notifications' => $notifications,
        ]);
    }

    public function markAllNotificationsRead(): RedirectResponse
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();
        $customer->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', 'Semua notifikasi telah ditandai sudah dibaca.');
    }
}
