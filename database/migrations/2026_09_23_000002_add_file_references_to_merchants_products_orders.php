<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('logo_file_id', 40)->nullable()->comment('files.public_id of the shop logo');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('image_file_id', 40)->nullable()->comment('files.public_id of the product photo; legacy image column is left untouched');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('signature_file_id', 40)->nullable()->comment('files.public_id of the customer signature; legacy signature column is left untouched');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('signature_file_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_file_id');
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('logo_file_id');
        });
    }
};
