<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Shared\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

final class CustomerAuthController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function showLogin(): View
    {
        return view('ordering::customer.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [], [
            'email' => 'surel',
            'password' => 'kata sandi',
        ]);

        $email = strtolower(trim($credentials['email']));

        $customer = Customer::query()->where('email', $email)->first();

        if (! $customer instanceof Customer || ! Hash::check($credentials['password'], (string) $customer->password)) {
            return back()
                ->withErrors(['email' => 'Surel atau kata sandi tidak sesuai.'])
                ->onlyInput('email');
        }

        Auth::guard('customer')->login($customer, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('customer.landing'));
    }

    public function showRegister(): View
    {
        return view('ordering::customer.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:150', 'unique:customers,email'],
            'phone' => ['required', 'string', 'max:30', 'unique:customers,phone'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'email.unique' => 'Surel ini sudah terdaftar sebagai akun anggota.',
            'phone.unique' => 'Nomor WhatsApp ini sudah terdaftar pada akun lain.',
        ], [
            'name' => 'nama lengkap',
            'email' => 'surel',
            'phone' => 'nomor WhatsApp',
            'password' => 'kata sandi',
        ]);

        $customer = new Customer;
        $customer->forceFill([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'phone' => trim($data['phone']),
            'password' => Hash::make($data['password']),
        ])->save();

        $this->audit->record(
            action: 'customer_registered',
            modelType: Customer::class,
            modelId: $customer->id,
            oldValues: null,
            newValues: ['name' => $customer->name, 'email' => $customer->email],
            actorType: 'system',
        );

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        return redirect()
            ->route('customer.landing')
            ->with('status', 'Pendaftaran berhasil. Silakan pilih koperasi untuk mulai berbelanja.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('customer.landing')
            ->with('status', 'Anda telah keluar dari akun anggota.');
    }
}
