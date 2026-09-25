<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedInteger('registration_fee')->nullable()->comment('Signup fee in SLSH; null uses REGISTRATION_FEE');
            $table->unsignedInteger('registration_fee_charge')->nullable()->comment('Charge added to the signup fee in SLSH; null uses REGISTRATION_FEE_CHARGE');
            $table->unsignedInteger('verification_fee')->nullable()->comment('Wallet verification fee in SLSH; null uses VERIFICATION_FEE');
            $table->unsignedInteger('verification_fee_charge')->nullable()->comment('Charge added to the verification fee in SLSH; null uses VERIFICATION_FEE_CHARGE');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['registration_fee', 'registration_fee_charge', 'verification_fee', 'verification_fee_charge']);
        });
    }
};
