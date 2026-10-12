<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('price_currency', 4)->default('USD')->after('price');
            $table->unsignedBigInteger('price_amount')->nullable()->after('price_currency')->comment('Price as entered: cents for USD, whole shillings for SLSH');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->string('price_currency', 4)->default('USD')->after('price');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('currency', 4)->default('USD')->after('total_price')->comment('Currency the sale was priced in');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['price_currency', 'price_amount']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('price_currency');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
