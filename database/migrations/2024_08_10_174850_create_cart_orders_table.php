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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->string('order_status')->default('pending'); // Pending, completed, failed, etc.
            $table->string('name')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('signature')->nullable();
             $table->decimal('total_price', 10, 2);
            $table->decimal('vat', 10, 2); // 10% VAT
            $table->decimal('exelo_amount', 10, 2);
            $table->decimal('sub_total', 10, 2);
            $table->string('order_type'); // 'shop' or 'stock'
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
