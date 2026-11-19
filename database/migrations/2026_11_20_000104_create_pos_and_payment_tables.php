<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('koperasi_id')->constrained('koperasis')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->unsignedBigInteger('opening_balance');
            $table->unsignedBigInteger('expected_cash')->nullable();
            $table->unsignedBigInteger('actual_cash')->nullable();
            $table->bigInteger('difference')->nullable(); // SIGNED: selisih kas bisa minus/plus
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();

            $table->index(['koperasi_id', 'user_id', 'status']);
        });

        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('koperasi_id')->constrained('koperasis')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('cashier_shifts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('invoice_number', 60)->unique();
            $table->unsignedBigInteger('total_amount');
            $table->enum('payment_method', ['cash', 'qris']);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->unsignedBigInteger('change_amount')->default(0);
            $table->enum('status', ['open', 'paid', 'cancelled', 'void_requested', 'voided', 'refunded'])
                ->default('open');
            $table->timestamps();

            $table->index(['koperasi_id', 'status', 'created_at']);
            $table->index(['koperasi_id', 'shift_id']);
        });

        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('koperasi_id')->constrained('koperasis')->restrictOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('product_name', 180);
            $table->unsignedBigInteger('price_snapshot');
            $table->unsignedBigInteger('cost_snapshot');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('subtotal');
            $table->timestamps();

            $table->index(['koperasi_id', 'sale_id']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('koperasi_id')->constrained('koperasis')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->restrictOnDelete();
            $table->string('payment_reference', 120)->unique();
            $table->unsignedBigInteger('amount');
            $table->enum('status', ['created', 'pending', 'settled', 'expired', 'denied'])
                ->default('created');
            $table->timestamp('expired_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['koperasi_id', 'status', 'expired_at']);
        });

        // SRS v3.2 NFR-DAT-08: tepat satu dari order_id atau sale_id wajib terisi (XOR)
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT chk_payments_xor CHECK ((order_id IS NOT NULL AND sale_id IS NULL) OR (order_id IS NULL AND sale_id IS NOT NULL))'
        );

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('provider_event_id', 150)->unique();
            $table->json('payload');
            $table->boolean('signature_valid');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('cashier_shifts');
    }
};
