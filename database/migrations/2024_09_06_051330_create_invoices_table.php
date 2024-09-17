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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->onDelete('cascade');
            $table->foreignId('order_id')->nullable()->constrained('orders')->onDelete('cascade');
            $table->string('invoice_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('mobile_number');
            $table->string('transaction_id');
            $table->string('hash');
            $table->decimal('amount', 10, 2);
            $table->string('currency');
            $table->string('status')->nullable();
            $table->string('e_transaction_id')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
