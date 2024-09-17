<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_name');
            $table->foreignId('category_id')->constrained('categories'); // Assuming you have a categories table
            $table->foreignId('merchant_id')->constrained('merchants');
            $table->decimal('price', 10, 2);
            $table->integer('stock_limit');
            $table->integer('alarm_limit');
            $table->string('image')->nullable();
            $table->string('bar_code');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
