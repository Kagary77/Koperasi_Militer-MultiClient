<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('koperasi_id')->constrained('koperasis')->restrictOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('total_sales')->default(0);
            $table->unsignedInteger('total_transactions')->default(0);
            $table->unsignedInteger('total_online_orders')->default(0);
            $table->unsignedInteger('total_pos_orders')->default(0);
            $table->unsignedInteger('total_active_customers')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['koperasi_id', 'date']);
            $table->index('date');
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->enum('actor_type', ['user', 'system', 'webhook']);
            $table->foreignId('koperasi_id')->nullable()->constrained('koperasis')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('model_type', 150);
            $table->unsignedBigInteger('model_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['koperasi_id', 'created_at']);
            $table->index(['actor_type', 'action', 'created_at']);
            $table->index(['model_type', 'model_id']);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type', 150);
            $table->unsignedBigInteger('notifiable_id');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('report_snapshots');
    }
};
