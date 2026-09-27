<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * products.merchant_id and categories.merchant_id used to hold a SHOP id. Each becomes
 * shop_id (FK shops.id), and a new merchant_id (FK merchants.id) records which merchant
 * owns that shop, the same split already done for employees. See docs/data-model.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // products: merchant_id -> shop_id, new real merchant_id
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign('products_merchant_id_foreign');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_merchant_client_uuid_unique');
            $table->renameColumn('merchant_id', 'shop_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('shop_id', 'products_shop_id_foreign')->references('id')->on('shops');
            $table->unique(['shop_id', 'client_uuid'], 'products_shop_client_uuid_unique');
            $table->unsignedBigInteger('merchant_id')->nullable()->after('shop_id')->comment('The merchant that owns the shop (merchants.id)');
        });

        DB::statement('UPDATE products JOIN shops ON shops.id = products.shop_id SET products.merchant_id = shops.merchant_id');

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('merchant_id', 'products_merchant_id_foreign')->references('id')->on('merchants')->nullOnDelete();
        });

        // categories: merchant_id -> shop_id, new real merchant_id
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign('categories_merchant_id_foreign');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_merchant_id_foreign');
            $table->renameColumn('merchant_id', 'shop_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('shop_id', 'categories_shop_id_foreign')->references('id')->on('shops')->cascadeOnDelete();
            $table->index('shop_id', 'categories_shop_id_index');
            $table->unsignedBigInteger('merchant_id')->nullable()->after('shop_id')->comment('The merchant that owns the shop (merchants.id)');
        });

        DB::statement('UPDATE categories JOIN shops ON shops.id = categories.shop_id SET categories.merchant_id = shops.merchant_id');

        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('merchant_id', 'categories_merchant_id_foreign')->references('id')->on('merchants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign('categories_merchant_id_foreign');
            $table->dropColumn('merchant_id');
            $table->dropForeign('categories_shop_id_foreign');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_shop_id_index');
            $table->renameColumn('shop_id', 'merchant_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('merchant_id', 'categories_merchant_id_foreign')->references('id')->on('shops')->cascadeOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign('products_merchant_id_foreign');
            $table->dropColumn('merchant_id');
            $table->dropForeign('products_shop_id_foreign');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_shop_client_uuid_unique');
            $table->renameColumn('shop_id', 'merchant_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('merchant_id', 'products_merchant_id_foreign')->references('id')->on('shops');
            $table->unique(['merchant_id', 'client_uuid'], 'products_merchant_client_uuid_unique');
        });
    }
};
