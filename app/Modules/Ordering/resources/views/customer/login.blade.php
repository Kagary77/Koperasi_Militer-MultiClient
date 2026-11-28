<x-layouts.customer title="Masuk Anggota">
    <section class="mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h1 class="text-xl font-bold">Masuk Akun Anggota</h1>
        <p class="mt-1 text-sm text-slate-500">Masuk untuk menyimpan keranjang, membayar dengan QRIS, dan memantau status pesanan.</p>

        <form method="POST" action="{{ route('customer.login.store') }}" class="mt-5 space-y-4">
            @csrf

            <div>
                <x-input name="email" label="Surel" type="email" :value="old('email')" required autofocus />
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-input name="password" label="Kata Sandi" type="password" required />
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <label class="flex min-h-11 items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="remember" value="1" class="size-4 rounded border-slate-300">
                <span>Ingat saya di perangkat ini</span>
            </label>

            <x-button type="submit" class="w-full">Masuk</x-button>
        </form>

        <p class="mt-4 text-center text-sm text-slate-500">
            Belum memiliki akun anggota?
            <a href="{{ route('customer.register') }}" class="font-semibold text-slate-900 underline dark:text-white">Daftar di sini</a>
        </p>
    </section>
</x-layouts.customer>