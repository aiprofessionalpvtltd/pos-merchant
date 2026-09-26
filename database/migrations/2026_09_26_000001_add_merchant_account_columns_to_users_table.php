<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A merchant account has its own mobile number, separate from its shops' numbers
     * (docs/merchant-onboarding.md). Existing owners get their first shop's number,
     * which is the number they already sign in with.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number', 20)->nullable()->unique()->after('email')->comment('The merchant\'s own mobile number; signs in with it');
            $table->timestamp('phone_verified_at')->nullable()->after('phone_number')->comment('Set when a verification fee was paid from phone_number');
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->date('dob')->nullable()->after('last_name');
        });

        DB::table('merchants')
            ->whereNotNull('user_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'user_id', 'phone_number', 'first_name', 'last_name', 'dob'])
            ->unique('user_id')
            ->each(function ($shop) {
                DB::table('users')
                    ->where('id', $shop->user_id)
                    ->where('user_type', 'merchant')
                    ->whereNull('phone_number')
                    ->update([
                        'phone_number' => $shop->phone_number,
                        'first_name' => $shop->first_name,
                        'last_name' => $shop->last_name,
                        'dob' => $shop->dob,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_number']);
            $table->dropColumn(['phone_number', 'phone_verified_at', 'first_name', 'last_name', 'dob']);
        });
    }
};
