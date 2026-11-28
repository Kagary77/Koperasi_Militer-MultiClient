<x-layouts.customer title="Notifikasi Anggota">
    <section class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
            <div>
                <h1 class="text-lg font-bold">Kotak Masuk Notifikasi</h1>
                <p class="text-xs text-slate-500">Pemberitahuan perubahan status pesanan, keranjang belanja, dan tanggapan pengaduan.</p>
            </div>

            @if ($notifications->whereNull('read_at')->isNotEmpty())
                <form method="POST" action="{{ route('customer.notifications.read-all') }}">
                    @csrf
                    <x-button type="submit" variant="secondary">Tandai Semua Dibaca</x-button>
                </form>
            @endif
        </div>

        <div class="mt-4 space-y-3">
            @forelse ($notifications as $notification)
                <article @class([
                    'rounded-xl border p-4 text-sm',
                    'border-slate-900 bg-slate-50 dark:border-slate-300 dark:bg-slate-800/70' => $notification->read_at === null,
                    'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900' => $notification->read_at !== null,
                ])>
                    <div class="flex items-start justify-between gap-3">
                        <p class="font-medium text-slate-800 dark:text-slate-100">
                            {{ $notification->data['message'] ?? 'Pemberitahuan baru dari koperasi.' }}
                        </p>
                        @if ($notification->read_at === null)
                            <span class="shrink-0 rounded-full bg-slate-900 px-2 py-0.5 text-[10px] font-bold uppercase text-white dark:bg-white dark:text-slate-900">
                                Baru
                            </span>
                        @endif
                    </div>
                    <p class="mt-1.5 text-xs text-slate-500">
                        {{ $notification->created_at->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB
                    </p>
                </article>
            @empty
                <x-empty-state
                    title="Belum ada notifikasi"
                    description="Pemberitahuan penting terkait pesanan dan keranjang Anda akan muncul di sini."
                />
            @endforelse
        </div>
    </section>
</x-layouts.customer>