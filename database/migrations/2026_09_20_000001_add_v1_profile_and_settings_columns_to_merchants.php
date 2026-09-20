<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->comment('Bumped on every profile, wallet or settings write; used for If-Match');

            $table->string('default_rail', 10)->nullable()->comment('zaad, edahab, golis or evc');
            $table->json('wallet_states')->nullable()->comment('Per rail: status, verified_at, rejection_reason. Missing = verified if a number is on file (legacy)');

            $table->unsignedInteger('exchange_rate')->nullable()->comment('SLSH per USD; null falls back to config exelo.conversion_rate');
            $table->timestamp('exchange_rate_updated_at')->nullable();
            $table->decimal('vat_rate', 5, 4)->default(0.05)->comment('Fraction, 0.05 = 5%');
            $table->boolean('is_vat_inclusive')->default(false)->comment('Prices already include VAT');
            $table->string('timezone', 50)->default('Africa/Mogadishu');
            $table->string('language', 5)->default('en');
            $table->json('preferences')->nullable()->comment('receipt, register and alerts options; missing keys use defaults');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn([
                'version', 'default_rail', 'wallet_states', 'exchange_rate', 'exchange_rate_updated_at',
                'vat_rate', 'is_vat_inclusive', 'timezone', 'language', 'preferences',
            ]);
        });
    }
};
