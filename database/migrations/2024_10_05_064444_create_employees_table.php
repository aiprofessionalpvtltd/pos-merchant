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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade'); // Associate employee with merchant
            $table->foreignId('merchant_id')->constrained()->onDelete('cascade'); // Associate employee with merchant
            $table->string('phone_number');
            $table->string('first_name');
            $table->string('last_name');
            $table->date('dob');
            $table->string('location');
            $table->string('role');
            $table->decimal('salary', 10, 2);
            $table->enum('status', ['active', 'inactive'])->default('active'); // Status column with default value
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
