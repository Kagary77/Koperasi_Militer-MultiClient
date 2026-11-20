<?php

namespace App\Http\Middleware;

use App\Models\Koperasi;
use App\Models\User;
use App\Support\Tenancy\KoperasiContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class SetKoperasiContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $koperasi = $request->route('koperasi');

        if (! $koperasi instanceof Koperasi) {
            $koperasi = Koperasi::query()->where('slug', (string) $koperasi)->first();
        }

        if (! $koperasi instanceof Koperasi) {
            abort(404);
        }

        if (! $koperasi->isActive()) {
            abort(403);
        }

        $user = $request->user('web');

        if (! $user instanceof User) {
            abort(403);
        }

        // UC-22 alur 3a: jika akun karyawan dinonaktifkan, akhiri sesi aktifnya segera.
        if (! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Akun Anda telah dinonaktifkan oleh Admin Koperasi.']);
        }

        if (
            ! in_array($user->role, ['admin_koperasi', 'kasir'], true)
            || (int) $user->koperasi_id !== (int) $koperasi->id
        ) {
            abort(403);
        }

        $context = app(KoperasiContext::class);
        $context->set($koperasi);

        try {
            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
