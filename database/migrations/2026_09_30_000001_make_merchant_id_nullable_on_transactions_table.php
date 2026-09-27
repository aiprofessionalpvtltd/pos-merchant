<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registration and verification payments happen before any shop exists, so a
 * transaction row synced from one of those invoices has no shop to point at.
 * See InvoiceObserver and docs/data-model.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign('transactions_merchant_id_foreign');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->nullable()->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('merchant_id', 'transactions_merchant_id_foreign')->references('id')->on('shops')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign('transactions_merchant_id_foreign');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->nullable(false)->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('merchant_id', 'transactions_merchant_id_foreign')->references('id')->on('shops')->cascadeOnDelete();
        });
    }
};
