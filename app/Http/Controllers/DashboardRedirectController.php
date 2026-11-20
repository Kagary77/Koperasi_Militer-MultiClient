<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DashboardRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user('web');

        if (! $user instanceof User || ! $user->isActive()) {
            abort(403);
        }

        if ($user->isSuperAdmin()) {
            return redirect()->route('pusat.dashboard');
        }

        $koperasi = $user->koperasi;

        if ($koperasi !== null && $koperasi->isActive()) {
            return redirect()->route('koperasi.dashboard', ['koperasi' => $koperasi->slug]);
        }

        abort(403, 'Akun tidak terhubung dengan koperasi aktif.');
    }
}
