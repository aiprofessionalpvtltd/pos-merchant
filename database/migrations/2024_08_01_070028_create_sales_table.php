<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants');
            $table->decimal('amount', 10, 2);
            $table->enum('payment_method', ['cash', 'card']);
            $table->boolean('is_successful')->default(false);
            $table->boolean('is_completed')->default(false);

            // New fields based on the calculation function
            $table->decimal('total_amount_after_conversion', 10, 2)->nullable();
            $table->decimal('amount_to_merchant', 10, 2)->nullable();
            $table->decimal('conversion_fee_amount', 10, 2)->nullable();
            $table->decimal('transaction_fee_amount', 10, 2)->nullable();
            $table->decimal('total_fee_charge_to_customer', 10, 2)->nullable();
            $table->decimal('amount_sent_to_exelo', 10, 2)->nullable();
            $table->decimal('total_amount_charge_to_customer', 10, 2)->nullable();
            $table->decimal('conversion_rate', 10, 2)->nullable();
            $table->string('currency')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
