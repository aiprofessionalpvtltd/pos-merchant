<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->comment('Bumped on every status change; used for sync');
            $table->timestamp('paid_at')->nullable()->comment('Set only when money was received; Complete without paid_at means fulfilled unpaid');
            $table->string('payment_method', 20)->nullable()->comment('Rail that settled the order: cash, zaad, edahab, card, nfc');
            $table->string('note')->nullable();
            $table->string('client_order_id', 64)->nullable()->comment('Local id when an order was composed offline');
            $table->string('cancel_reason')->nullable();
            $table->timestamp('stock_deducted_at')->nullable()->comment('Stock leaves the shelf once, when the order is first paid or completed');
            $table->unique(['merchant_id', 'client_order_id'], 'orders_merchant_client_order_unique');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('purpose', 20)->nullable()->comment('Sale charges only: pos_sale or order_settlement');
            $table->unsignedBigInteger('cart_id')->nullable();
            $table->unsignedInteger('cart_version')->nullable();
            $table->json('meta')->nullable()->comment('Sale charges: customer, USD amounts and the ticket lines being paid for');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'cart_id', 'cart_version', 'meta']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_merchant_client_order_unique');
            $table->dropColumn(['version', 'paid_at', 'payment_method', 'note', 'client_order_id', 'cancel_reason', 'stock_deducted_at']);
        });
    }
};
