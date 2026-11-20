<?php

use App\Models\Category;
use App\Models\Koperasi;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Catalog\Events\CatalogChanged;
use App\Modules\Catalog\Services\ProductPhotoStore;
use App\Modules\Catalog\Services\ProductStockService;
use App\Services\Shared\AuditLogger;
use App\Support\Tenancy\KoperasiContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public int $koperasiId = 0;

    // Form Kategori Tambahan Koperasi
    public string $newCategoryName = '';

    // Form Produk (Tambah / Ubah)
    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public ?int $categoryId = null;

    public ?int $unitId = null;

    public string $barcode = '';

    public int $price = 0;

    public int $costPrice = 0;

    public int $stock = 0;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $photo = null;

    // Form Stock Adjustment
    public ?int $adjustingProductId = null;

    public string $adjustmentType = 'in';

    public int $adjustmentQty = 1;

    public string $adjustmentNotes = '';

    // Pencarian & Filter
    public string $search = '';

    public ?int $filterCategoryId = null;

    public function mount(int $koperasiId): void
    {
        $this->koperasiId = $koperasiId;
    }

    public function booted(): void
    {
        $user = Auth::guard('web')->user();

        abort_unless(
            $user instanceof User
            && $user->isActive()
            && in_array($user->role, ['admin_koperasi', 'kasir'], true)
            && (int) $user->koperasi_id === $this->koperasiId,
            403,
        );

        app(KoperasiContext::class)->set(Koperasi::query()->findOrFail($this->koperasiId));
    }

    #[Computed]
    public function isAdmin(): bool
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User && $user->isAdminKoperasi();
    }

    /** @return Collection<int, Category> */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->availableForKoperasi($this->koperasiId)
            ->orderByRaw('koperasi_id IS NOT NULL')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Unit> */
    #[Computed]
    public function units(): Collection
    {
        return Unit::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Product> */
    #[Computed]
    public function products(): Collection
    {
        return Product::query()
            ->with(['category:id,name,koperasi_id', 'unit:id,name'])
            ->when($this->search !== '', function ($query): void {
                $like = '%'.$this->search.'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('barcode', 'like', $like));
            })
            ->when($this->filterCategoryId !== null, fn ($query) => $query->where('category_id', $this->filterCategoryId))
            ->orderBy('name')
            ->get();
    }

    public function addCategory(): void
    {
        abort_unless($this->isAdmin(), 403);

        $data = $this->validate([
            'newCategoryName' => ['required', 'string', 'max:100'],
        ], [], [
            'newCategoryName' => 'nama kategori',
        ]);

        $slug = Str::slug($data['newCategoryName']);

        $exists = Category::query()
            ->where('slug', $slug)
            ->where(fn ($q) => $q->whereNull('koperasi_id')->orWhere('koperasi_id', $this->koperasiId))
            ->exists();

        if ($exists) {
            $this->addError('newCategoryName', 'Kategori dengan nama tersebut sudah tersedia.');

            return;
        }

        $category = new Category;
        $category->forceFill([
            'koperasi_id' => $this->koperasiId,
            'name' => trim($data['newCategoryName']),
            'slug' => $slug,
            'is_active' => true,
        ])->save();

        app(AuditLogger::class)->record(
            action: 'category_created',
            modelType: Category::class,
            modelId: $category->id,
            oldValues: null,
            newValues: ['name' => $category->name, 'slug' => $category->slug],
            koperasiId: $this->koperasiId,
        );

        $this->newCategoryName = '';
        unset($this->categories);
        session()->flash('status', "Kategori tambahan \"{$category->name}\" berhasil dibuat.");
    }

    public function saveProduct(): void
    {
        abort_unless($this->isAdmin(), 403);

        // Normalisasi barcode kosong menjadi null agar constraint UNIQUE tidak bentrok pada string kosong.
        $normalizedBarcode = trim($this->barcode) !== '' ? trim($this->barcode) : null;
        $this->barcode = $normalizedBarcode ?? '';

        $rules = [
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:1000'],
            'categoryId' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(function ($query): void {
                    $query->where('is_active', true)
                        ->where(fn ($q) => $q->whereNull('koperasi_id')->orWhere('koperasi_id', $this->koperasiId));
                }),
            ],
            'unitId' => [
                'required',
                'integer',
                Rule::exists('units', 'id')->where('is_active', true),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique('products', 'barcode')
                    ->where('koperasi_id', $this->koperasiId)
                    ->ignore($this->editingId),
            ],
            'price' => ['required', 'integer', 'min:1'],
            'costPrice' => ['required', 'integer', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];

        $data = $this->validate($rules, [
            'barcode.unique' => 'Barcode sudah digunakan produk lain di koperasi ini.',
        ], [
            'name' => 'nama produk',
            'description' => 'deskripsi',
            'categoryId' => 'kategori',
            'unitId' => 'satuan',
            'barcode' => 'barcode',
            'price' => 'harga jual',
            'costPrice' => 'harga beli',
            'stock' => 'stok awal',
            'photo' => 'foto produk',
        ]);

        $photoStore = app(ProductPhotoStore::class);

        if ($this->editingId === null) {
            $slug = $this->uniqueSlug($data['name']);
            $imagePath = $this->photo ? $photoStore->store($this->photo, $this->koperasiId) : null;

            $product = Product::create([
                'category_id' => $data['categoryId'],
                'unit_id' => $data['unitId'],
                'name' => trim($data['name']),
                'slug' => $slug,
                'description' => trim((string) $data['description']) ?: null,
                'barcode' => $normalizedBarcode,
                'price' => $data['price'],
                'cost_price' => $data['costPrice'],
                'stock' => $data['stock'],
                'is_available' => true,
                'image_path' => $imagePath,
            ]);

            app(AuditLogger::class)->record(
                action: 'product_created',
                modelType: Product::class,
                modelId: $product->id,
                oldValues: null,
                newValues: $product->only(['name', 'price', 'cost_price', 'stock', 'barcode']),
                koperasiId: $this->koperasiId,
            );

            event(new CatalogChanged($this->koperasiId, (int) $product->id));
            session()->flash('status', "Produk \"{$product->name}\" berhasil ditambahkan.");
        } else {
            $product = Product::query()->findOrFail($this->editingId);
            $before = $product->only(['name', 'price', 'cost_price', 'category_id', 'unit_id', 'barcode']);

            $imagePath = $product->image_path;
            if ($this->photo) {
                $photoStore->delete($product->image_path);
                $imagePath = $photoStore->store($this->photo, $this->koperasiId);
            }

            $product->update([
                'category_id' => $data['categoryId'],
                'unit_id' => $data['unitId'],
                'name' => trim($data['name']),
                'slug' => $this->uniqueSlug($data['name'], $product->id),
                'description' => trim((string) $data['description']) ?: null,
                'barcode' => $normalizedBarcode,
                'price' => $data['price'],
                'cost_price' => $data['costPrice'],
                'image_path' => $imagePath,
            ]);

            app(AuditLogger::class)->record(
                action: 'product_updated',
                modelType: Product::class,
                modelId: $product->id,
                oldValues: $before,
                newValues: $product->only(['name', 'price', 'cost_price', 'category_id', 'unit_id', 'barcode']),
                koperasiId: $this->koperasiId,
            );

            event(new CatalogChanged($this->koperasiId, (int) $product->id));
            session()->flash('status', "Produk \"{$product->name}\" berhasil diperbarui.");
        }

        $this->resetProductForm();
    }

    public function editProduct(int $productId): void
    {
        abort_unless($this->isAdmin(), 403);

        $product = Product::query()->findOrFail($productId);

        $this->editingId = $product->id;
        $this->name = $product->name;
        $this->description = (string) $product->description;
        $this->categoryId = $product->category_id;
        $this->unitId = $product->unit_id;
        $this->barcode = (string) $product->barcode;
        $this->price = $product->price;
        $this->costPrice = $product->cost_price;
        $this->stock = $product->stock;
        $this->photo = null;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->resetProductForm();
    }

    public function startAdjustment(int $productId): void
    {
        abort_unless($this->isAdmin(), 403);

        $product = Product::query()->findOrFail($productId);
        $this->adjustingProductId = $product->id;
        $this->adjustmentType = 'in';
        $this->adjustmentQty = 1;
        $this->adjustmentNotes = '';
        $this->resetErrorBag('adjustment');
    }

    public function cancelAdjustment(): void
    {
        $this->reset(['adjustingProductId', 'adjustmentType', 'adjustmentQty', 'adjustmentNotes']);
        $this->adjustmentType = 'in';
        $this->adjustmentQty = 1;
    }

    public function submitAdjustment(): void
    {
        abort_unless($this->isAdmin(), 403);

        $this->validate([
            'adjustingProductId' => ['required', 'integer'],
            'adjustmentType' => ['required', Rule::in(array_keys(ProductStockService::ADJUSTMENT_TYPES))],
            'adjustmentQty' => ['required', 'integer', 'min:1'],
            'adjustmentNotes' => ['required', 'string', 'max:250'],
        ], [], [
            'adjustmentType' => 'jenis penyesuaian',
            'adjustmentQty' => 'jumlah',
            'adjustmentNotes' => 'alasan',
        ]);

        $product = Product::query()->findOrFail((int) $this->adjustingProductId);

        try {
            app(ProductStockService::class)->adjustStock(
                product: $product,
                adjustmentType: $this->adjustmentType,
                quantity: $this->adjustmentQty,
                notes: $this->adjustmentNotes,
            );
        } catch (\DomainException $e) {
            $this->addError('adjustment', $e->getMessage());

            return;
        }

        $this->cancelAdjustment();
        unset($this->products);
        session()->flash('status', "Stok produk \"{$product->name}\" berhasil disesuaikan.");
    }

    /**
     * UC-13: Sakelar cepat ketersediaan — diizinkan untuk Admin Koperasi maupun Kasir.
     */
    public function toggleAvailability(int $productId): void
    {
        $product = Product::query()->findOrFail($productId);
        $updated = app(ProductStockService::class)->toggleAvailability($product);

        unset($this->products);
        session()->flash(
            'status',
            $updated->is_available
                ? "Produk \"{$updated->name}\" ditandai Tersedia."
                : "Produk \"{$updated->name}\" ditandai Habis.",
        );
    }

    public function deleteProduct(int $productId): void
    {
        abort_unless($this->isAdmin(), 403);

        $product = Product::query()->findOrFail($productId);
        $name = $product->name;

        app(ProductStockService::class)->softDelete($product);

        if ($this->editingId === $productId) {
            $this->resetProductForm();
        }

        unset($this->products);
        session()->flash('status', "Produk \"{$name}\" dihapus (soft delete).");
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;

        while (
            Product::withTrashed()
                ->where('koperasi_id', $this->koperasiId)
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    private function resetProductForm(): void
    {
        $this->reset(['editingId', 'name', 'description', 'categoryId', 'unitId', 'barcode', 'price', 'costPrice', 'stock', 'photo']);
        $this->resetValidation();
        unset($this->products);
    }
};
?>

<div class="space-y-6">
    @if (session('status'))
        <div class="rounded-lg bg-emerald-100 px-4 py-3 text-sm font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    @if ($this->isAdmin)
        {{-- Kategori Tambahan Koperasi --}}
        <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-base font-bold">Kategori Produk (Master Global &amp; Tambahan Koperasi)</h2>
            <form wire:submit="addCategory" class="mt-3 flex flex-wrap items-end gap-2">
                <div class="min-w-[14rem] flex-1">
                    <x-input name="newCategoryName" label="Tambah Kategori Khusus Koperasi" wire:model="newCategoryName" placeholder="Mis. Perlengkapan Dinas" />
                    @error('newCategoryName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <x-button type="submit">Tambah Kategori</x-button>
            </form>
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($this->categories as $cat)
                    <span class="rounded-full border border-slate-200 px-2.5 py-0.5 text-xs dark:border-slate-700">
                        {{ $cat->name }}
                        @if ($cat->koperasi_id === null)
                            <span class="text-slate-400">(Global)</span>
                        @endif
                    </span>
                @endforeach
            </div>
        </section>

        {{-- Form Tambah / Ubah Produk --}}
        <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-base font-bold">{{ $editingId ? 'Ubah Produk' : 'Tambah Produk Baru' }}</h2>

            <form wire:submit="saveProduct" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <x-input name="name" label="Nama Produk" wire:model="name" required />
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-col gap-1">
                    <label for="categoryId" class="text-sm font-medium text-slate-700 dark:text-slate-200">Kategori</label>
                    <select id="categoryId" wire:model="categoryId" class="min-h-11 rounded-lg border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-800">
                        <option value="">Pilih kategori</option>
                        @foreach ($this->categories as $cat)
                            <option value="{{ $cat->id }}" wire:key="cat-{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('categoryId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-col gap-1">
                    <label for="unitId" class="text-sm font-medium text-slate-700 dark:text-slate-200">Satuan Standar</label>
                    <select id="unitId" wire:model="unitId" class="min-h-11 rounded-lg border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-800">
                        <option value="">Pilih satuan</option>
                        @foreach ($this->units as $unit)
                            <option value="{{ $unit->id }}" wire:key="unit-{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    @error('unitId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-input name="price" label="Harga Jual (Rp)" type="number" min="1" wire:model="price" required />
                    @error('price') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-input name="costPrice" label="Harga Beli / Modal (Rp)" type="number" min="0" wire:model="costPrice" required />
                    @error('costPrice') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-input name="barcode" label="Barcode (Opsional)" wire:model="barcode" placeholder="899..." />
                    @error('barcode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                @if ($editingId === null)
                    <div>
                        <x-input name="stock" label="Stok Awal" type="number" min="0" wire:model="stock" required />
                        @error('stock') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="sm:col-span-2">
                    <x-input name="description" label="Deskripsi (Opsional)" wire:model="description" />
                    @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-col gap-1 sm:col-span-2 lg:col-span-3">
                    <label for="photo" class="text-sm font-medium text-slate-700 dark:text-slate-200">Foto Produk (JPG/PNG/WebP, maks 2 MB — otomatis dikompresi WebP)</label>
                    <input id="photo" type="file" wire:model="photo" accept="image/*" class="text-sm" />
                    @error('photo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-3">
                    <x-button type="submit">{{ $editingId ? 'Simpan Perubahan' : 'Simpan Produk' }}</x-button>
                    @if ($editingId)
                        <x-button type="button" variant="secondary" wire:click="cancelEdit">Batal</x-button>
                    @endif
                </div>
            </form>
        </section>

        {{-- Panel Stock Adjustment --}}
        @if ($adjustingProductId !== null)
            @php($targetProduct = $this->products->firstWhere('id', $adjustingProductId))
            <section class="rounded-xl border-2 border-slate-900 bg-white p-5 dark:border-slate-100 dark:bg-slate-900" data-test="stock-adjustment-panel">
                <h2 class="text-base font-bold">
                    Penyesuaian Stok (Stock Adjustment) — {{ $targetProduct?->name }}
                    <span class="text-sm font-normal text-slate-500">(Stok saat ini: {{ $targetProduct?->stock }})</span>
                </h2>

                @error('adjustment')
                    <p class="mt-2 rounded-lg bg-red-100 px-3 py-2 text-sm text-red-800" role="alert">{{ $message }}</p>
                @enderror

                <form wire:submit="submitAdjustment" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="flex flex-col gap-1">
                        <label for="adjustmentType" class="text-sm font-medium">Jenis Penyesuaian</label>
                        <select id="adjustmentType" wire:model="adjustmentType" class="min-h-11 rounded-lg border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-800">
                            @foreach (\App\Modules\Catalog\Services\ProductStockService::ADJUSTMENT_TYPES as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <x-input name="adjustmentQty" label="Jumlah" type="number" min="1" wire:model="adjustmentQty" required />
                        @error('adjustmentQty') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <x-input name="adjustmentNotes" label="Alasan Penyesuaian" wire:model="adjustmentNotes" placeholder="Mis. Pembelian rutin / kemasan sobek" required />
                        @error('adjustmentNotes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex gap-2 sm:col-span-3">
                        <x-button type="submit">Simpan Penyesuaian</x-button>
                        <x-button type="button" variant="secondary" wire:click="cancelAdjustment">Batal</x-button>
                    </div>
                </form>
            </section>
        @endif
    @endif

    {{-- Daftar Produk & Sakelar Cepat Habis (UC-12 & UC-13) --}}
    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-bold">Daftar Produk &amp; Stok</h2>
            <div class="flex flex-wrap gap-2">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari nama atau barcode…"
                    class="min-h-11 rounded-lg border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-800"
                />
                <select wire:model.live="filterCategoryId" class="min-h-11 rounded-lg border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-800">
                    <option value="">Semua Kategori</option>
                    @foreach ($this->categories as $cat)
                        <option value="{{ $cat->id }}" wire:key="fcat-{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[52rem] text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-slate-800">
                    <tr>
                        <th class="py-3 pe-3">Foto</th>
                        <th class="py-3 pe-3">Produk</th>
                        <th class="py-3 pe-3">Kategori</th>
                        <th class="py-3 pe-3">Harga</th>
                        <th class="py-3 pe-3">Stok</th>
                        <th class="py-3 pe-3">Ketersediaan (UC-13)</th>
                        @if ($this->isAdmin)
                            <th class="py-3 text-right">Aksi</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($this->products as $product)
                        <tr wire:key="prod-{{ $product->id }}">
                            <td class="py-3 pe-3">
                                @if ($product->imageUrl())
                                    <img src="{{ $product->imageUrl() }}" alt="" class="size-11 rounded object-cover">
                                @else
                                    <span class="flex size-11 items-center justify-center rounded bg-slate-200 text-xs font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                                        {{ Str::upper(Str::substr($product->name, 0, 2)) }}
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 pe-3">
                                <p class="font-semibold">{{ $product->name }}</p>
                                @if ($product->barcode)
                                    <p class="font-mono text-xs text-slate-500">{{ $product->barcode }}</p>
                                @endif
                            </td>
                            <td class="py-3 pe-3">{{ $product->category?->name }}</td>
                            <td class="py-3 pe-3 font-medium">
                                Rp {{ number_format($product->price, 0, ',', '.') }}
                                <span class="text-xs text-slate-400">/ {{ $product->unit?->name }}</span>
                            </td>
                            <td class="py-3 pe-3 font-semibold {{ $product->stock <= 5 ? 'text-amber-600' : '' }}">
                                {{ $product->stock }}
                            </td>
                            <td class="py-3 pe-3">
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked="{{ $product->is_available ? 'true' : 'false' }}"
                                    wire:click="toggleAvailability({{ $product->id }})"
                                    class="inline-flex min-h-11 items-center gap-2"
                                    aria-label="Ketersediaan {{ $product->name }}"
                                >
                                    <span @class([
                                        'relative inline-block h-6 w-11 rounded-full transition',
                                        'bg-emerald-600' => $product->is_available,
                                        'bg-slate-400' => ! $product->is_available,
                                    ])>
                                        <span @class([
                                            'absolute top-0.5 size-5 rounded-full bg-white transition',
                                            'left-5' => $product->is_available,
                                            'left-0.5' => ! $product->is_available,
                                        ])></span>
                                    </span>
                                    <span class="text-xs font-semibold">
                                        {{ $product->is_available ? 'Tersedia' : 'Habis' }}
                                    </span>
                                </button>
                            </td>
                            @if ($this->isAdmin)
                                <td class="py-3 text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <button
                                            type="button"
                                            wire:click="startAdjustment({{ $product->id }})"
                                            class="min-h-11 rounded-lg border border-slate-300 px-3 text-xs font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                                        >
                                            Stok
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="editProduct({{ $product->id }})"
                                            class="min-h-11 rounded-lg border border-slate-300 px-3 text-xs font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                                        >
                                            Ubah
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="deleteProduct({{ $product->id }})"
                                            wire:confirm="Hapus produk {{ $product->name }}? Riwayat transaksi tetap terjaga."
                                            class="min-h-11 rounded-lg border border-red-300 px-3 text-xs font-medium text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400"
                                        >
                                            Hapus
                                        </button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $this->isAdmin ? 7 : 6 }}" class="py-6">
                                <x-empty-state title="Belum ada produk" description="Tambahkan produk sembako untuk mulai berjualan." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>