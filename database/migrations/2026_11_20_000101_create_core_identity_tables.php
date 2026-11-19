<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('koperasis', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 160)->unique();
            $table->text('address');
            $table->string('phone', 25);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->time('open_time');
            $table->time('close_time');
            $table->string('bank_name', 100);
            $table->string('bank_account_number', 50);
            $table->text('gateway_merchant_id'); // encrypted cast
            $table->text('gateway_credentials'); // encrypted:array cast (API key & webhook secret)
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('koperasi_id')
                ->nullable()
                ->after('id')
                ->constrained('koperasis')
                ->restrictOnDelete();
            $table->enum('role', ['super_admin', 'admin_koperasi', 'kasir'])
                ->default('kasir')
                ->after('password');
            $table->boolean('is_active')
                ->default(true)
                ->after('role');
            $table->text('mfa_secret')
                ->nullable()
                ->after('is_active');
            $table->softDeletes();

            $table->index(['koperasi_id', 'role', 'is_active']);
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 150)->unique();
            $table->string('phone', 25)->unique();
            $table->string('password', 255);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('global_settings', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_settings');
        Schema::dropIfExists('customers');

        Schema::table('users', function (Blueprint $table): void {
            // 1. Lepas foreign key lebih dulu sebelum menghapus index pendukungnya
            $table->dropForeign(['koperasi_id']);
            // 2. Hapus composite index
            $table->dropIndex(['koperasi_id', 'role', 'is_active']);
            // 3. Hapus seluruh kolom tambahan sekaligus
            $table->dropColumn(['koperasi_id', 'role', 'is_active', 'mfa_secret', 'deleted_at']);
        });

        Schema::dropIfExists('koperasis');
    }
};
