<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('koperasi_id')
                ->nullable()
                ->constrained('koperasis')
                ->restrictOnDelete();
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['koperasi_id', 'is_active']);
        });

        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('koperasi_id')->constrained('koperasis')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('name', 180);
            $table->string('slug', 200);
            $table->text('description')->nullable();
            $table->string('barcode', 80)->nullable();
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('cost_price')->default(0);
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('is_available')->default(true);
            $table->string('image_path', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['koperasi_id', 'slug']);
            $table->unique(['koperasi_id', 'barcode']);
            $table->index(['koperasi_id', 'category_id', 'is_available']);
            $table->index(['koperasi_id', 'is_available', 'stock']);
        });

        Schema::create('stock_mutations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('koperasi_id')->constrained('koperasis')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->enum('type', ['in', 'out_pos', 'out_online', 'return_online', 'return_pos', 'adjustment']);
            $table->integer('quantity'); // positif = masuk, negatif = keluar
            $table->enum('reference_type', ['order', 'sale', 'adjustment', 'reversal']);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['koperasi_id', 'product_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_mutations');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
    }
};
