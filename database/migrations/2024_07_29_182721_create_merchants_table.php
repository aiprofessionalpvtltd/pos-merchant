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
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

             $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('dob')->nullable();
            $table->string('location')->nullable();
            $table->string('business_name')->nullable();
            $table->string('merchant_code')->unique()->nullable();
            $table->string('email')->nullable(); // Adding nullable since email is empty in the input
            $table->string('phone_number')->unique();

            $table->boolean('is_approved')->default(false);
            $table->boolean('confirmation_status')->default(false);
            $table->integer('otp')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
