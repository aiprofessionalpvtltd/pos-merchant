<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * orders.merchant_id used to hold a SHOP id. It becomes shop_id (FK shops.id), and a new
 * merchant_id (FK merchants.id) records which merchant owns that shop, the same split
 * already done for employees, products and categories. See docs/data-model.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_merchant_id_foreign');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_merchant_client_order_unique');
            $table->renameColumn('merchant_id', 'shop_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('shop_id', 'orders_shop_id_foreign')->references('id')->on('shops')->cascadeOnDelete();
            $table->unique(['shop_id', 'client_order_id'], 'orders_shop_client_order_unique');
            $table->unsignedBigInteger('merchant_id')->nullable()->after('shop_id')->comment('The merchant that owns the shop (merchants.id)');
        });

        DB::statement('UPDATE orders JOIN shops ON shops.id = orders.shop_id SET orders.merchant_id = shops.merchant_id');

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('merchant_id', 'orders_merchant_id_foreign')->references('id')->on('merchants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_merchant_id_foreign');
            $table->dropColumn('merchant_id');
            $table->dropForeign('orders_shop_id_foreign');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_shop_client_order_unique');
            $table->renameColumn('shop_id', 'merchant_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('merchant_id', 'orders_merchant_id_foreign')->references('id')->on('shops')->cascadeOnDelete();
            $table->unique(['merchant_id', 'client_order_id'], 'orders_merchant_client_order_unique');
        });
    }
};
