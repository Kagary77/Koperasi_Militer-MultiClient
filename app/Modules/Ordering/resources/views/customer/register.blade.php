<x-layouts.customer title="Daftar Anggota">
    <section class="mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h1 class="text-xl font-bold">Daftar Akun Anggota Baru</h1>
        <p class="mt-1 text-sm text-slate-500">Nomor WhatsApp digunakan untuk pemberitahuan status pesanan dan ketersediaan barang.</p>

        <form method="POST" action="{{ route('customer.register.store') }}" class="mt-5 space-y-4">
            @csrf

            <div>
                <x-input name="name" label="Nama Lengkap" :value="old('name')" required autofocus />
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-input name="email" label="Surel" type="email" :value="old('email')" required />
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-input name="phone" label="Nomor WhatsApp" type="tel" :value="old('phone')" placeholder="0812xxxxxxxx" required />
                @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-input name="password" label="Kata Sandi (min. 8 karakter)" type="password" required />
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-input name="password_confirmation" label="Konfirmasi Kata Sandi" type="password" required />
            </div>

            <x-button type="submit" class="w-full">Daftar Sekarang</x-button>
        </form>

        <p class="mt-4 text-center text-sm text-slate-500">
            Sudah punya akun?
            <a href="{{ route('customer.login') }}" class="font-semibold text-slate-900 underline dark:text-white">Masuk</a>
        </p>
    </section>
</x-layouts.customer>