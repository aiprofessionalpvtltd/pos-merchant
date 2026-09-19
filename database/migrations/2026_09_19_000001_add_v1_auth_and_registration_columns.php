<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('pin_failed_attempts')->default(0)->comment('Consecutive wrong PIN entries');
            $table->timestamp('locked_until')->nullable()->comment('PIN login blocked until this time');
            $table->timestamp('pin_set_at')->nullable()->comment('When the hashed PIN was last set through the v1 API');
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->string('state')->nullable()->comment('State display name');
            $table->string('state_code')->nullable()->comment('State code from config/exelo.php');
            $table->string('city')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('public_id')->nullable()->unique()->comment('Client-facing id, inv_...');
            $table->string('rail')->nullable()->comment('zaad | edahab');
            $table->string('wallet_number')->nullable()->comment('Wallet billed; mobile_number is the account it is for');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('consumed_at')->nullable()->comment('Set once the invoice has created a merchant or completed verification');
            $table->string('error_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['public_id', 'rail', 'wallet_number', 'expires_at', 'paid_at', 'consumed_at', 'error_reason']);
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['state', 'state_code', 'city']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pin_failed_attempts', 'locked_until', 'pin_set_at']);
        });
    }
};
