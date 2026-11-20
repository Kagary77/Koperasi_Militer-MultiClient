<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMfaEnabled
{
    /** @var list<string> */
    private const MFA_SETUP_ROUTES = [
        'two-factor.show',
        'security.edit',
    ];

    public static function setupRouteName(): ?string
    {
        foreach (self::MFA_SETUP_ROUTES as $name) {
            if (Route::has($name)) {
                return $name;
            }
        }

        return null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('koperasi.enforce_mfa', false)) {
            return $next($request);
        }

        $user = $request->user('web');

        if (
            $user instanceof User
            && in_array($user->role, ['super_admin', 'admin_koperasi'], true)
            && $user->two_factor_confirmed_at === null
        ) {
            $setupRoute = self::setupRouteName();

            if ($setupRoute !== null) {
                return redirect()
                    ->route($setupRoute)
                    ->with('status', 'Autentikasi dua faktor (MFA) wajib diaktifkan untuk peran Anda.');
            }

            abort(403, 'Autentikasi dua faktor (MFA) wajib diaktifkan untuk peran Anda.');
        }

        return $next($request);
    }
}
