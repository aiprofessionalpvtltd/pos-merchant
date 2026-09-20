<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->comment('Bumped on every edit or stock movement; used for If-Match and sync');
            $table->string('client_uuid', 64)->nullable()->comment('Generated on the device when a product is created offline');
            $table->unique(['merchant_id', 'client_uuid'], 'products_merchant_client_uuid_unique');
        });

        Schema::table('inventory_histories', function (Blueprint $table) {
            $table->string('kind', 20)->default('transfer')->comment('transfer, adjustment, opening or sale');
            $table->string('reason', 20)->nullable()->comment('Adjustments only: recount, damage, theft, expiry, correction');
            $table->string('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_histories', function (Blueprint $table) {
            $table->dropColumn(['kind', 'reason', 'note']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_merchant_client_uuid_unique');
            $table->dropColumn(['version', 'client_uuid']);
        });
    }
};
