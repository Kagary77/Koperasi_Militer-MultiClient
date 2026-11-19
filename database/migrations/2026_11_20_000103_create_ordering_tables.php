<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('koperasi_id')->constrained('koperasis')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['koperasi_id', 'customer_id', 'product_id']);
            $table->index(['customer_id', 'koperasi_id']);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('koperasi_id')->constrained('koperasis')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('order_number', 60)->unique();
            $table->enum('status', ['pending_payment', 'paid', 'processing', 'ready', 'completed', 'cancelled'])
                ->default('pending_payment');
            $table->decimal('tax_rate_snapshot', 5, 2)->default(0.00);
            $table->unsignedBigInteger('total_tax')->default(0);
            $table->unsignedBigInteger('total_fee')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['koperasi_id', 'status', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('koperasi_id')->constrained('koperasis')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('product_name', 180);
            $table->unsignedBigInteger('price_snapshot');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('subtotal');
            $table->timestamps();

            $table->index(['koperasi_id', 'order_id']);
        });

        Schema::create('complaints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('koperasi_id')->constrained('koperasis')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->enum('category', ['product_quality', 'service', 'payment', 'other']);
            $table->string('subject', 150);
            $table->text('description');
            $table->string('attachment_path', 255)->nullable();
            $table->enum('status', ['open', 'processing', 'resolved', 'rejected'])->default('open');
            $table->text('admin_response')->nullable();
            $table->timestamps();

            $table->index(['koperasi_id', 'status', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cart_items');
    }
};
