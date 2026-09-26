<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * employees.merchant_id used to hold a SHOP id. It becomes shop_id (FK shops.id), and a new
 * merchant_id (FK merchants.id) records which merchant owns that shop. See docs/data-model.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->index('user_id', 'employees_user_id_index');
            $table->dropForeign('employees_merchant_id_foreign');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_person_shop_unique');
            $table->dropIndex('employees_shop_status_index');
            $table->renameColumn('merchant_id', 'shop_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('shop_id', 'employees_shop_id_foreign')->references('id')->on('shops')->cascadeOnDelete();
            $table->unique(['user_id', 'shop_id'], 'employees_person_shop_unique');
            $table->index(['shop_id', 'status'], 'employees_shop_status_index');
            $table->unsignedBigInteger('merchant_id')->nullable()->after('shop_id')->comment('The merchant that owns the shop (merchants.id)');
        });

        DB::statement('UPDATE employees JOIN shops ON shops.id = employees.shop_id SET employees.merchant_id = shops.merchant_id');

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('merchant_id', 'employees_merchant_id_foreign')->references('id')->on('merchants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign('employees_merchant_id_foreign');
            $table->dropColumn('merchant_id');
            $table->dropForeign('employees_shop_id_foreign');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_person_shop_unique');
            $table->dropIndex('employees_shop_status_index');
            $table->renameColumn('shop_id', 'merchant_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('merchant_id', 'employees_merchant_id_foreign')->references('id')->on('shops')->cascadeOnDelete();
            $table->unique(['user_id', 'merchant_id'], 'employees_person_shop_unique');
            $table->index(['merchant_id', 'status'], 'employees_shop_status_index');
            $table->dropIndex('employees_user_id_index');
        });
    }
};
