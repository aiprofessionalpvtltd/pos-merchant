<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->string('device_id', 64)->nullable()->comment('The till the ticket belongs to; null for carts made by the legacy app');
            $table->unsignedInteger('version')->default(1)->comment('Bumped on every change; used for If-Match and cart_version at payment');
            $table->index(['merchant_id', 'user_id', 'device_id', 'cart_type'], 'carts_till_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropIndex('carts_till_lookup');
            $table->dropColumn(['device_id', 'version']);
        });
    }
};
