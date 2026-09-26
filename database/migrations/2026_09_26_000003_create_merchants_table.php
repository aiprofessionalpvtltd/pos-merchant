<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merchants and shops in separate tables (docs/data-model.md):
     *
     *   users      sign-in only (PIN, tokens)
     *   merchants  the merchant account: own phone, name, date of birth, email, verification
     *   shops      the shops; shops.merchant_id → merchants.id (one merchant, many shops)
     *
     * Replaces the temporary `merchants` view and moves the merchant fields added to
     * `users` on 2026-09-26 into the new table.
     */
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS merchants');

        // `shops` kept the constraint names it had as `merchants` (e.g. merchants_user_id_foreign),
        // so the new table's constraints are named explicitly.
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('The sign-in (PIN, tokens) of this merchant');
            $table->unique('user_id', 'merchant_accounts_user_id_unique');
            $table->foreign('user_id', 'merchant_accounts_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('dob')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_number', 20)->nullable()->comment('The merchant\'s own mobile; signs in with it. Shops have their own numbers');
            $table->unique('phone_number', 'merchant_accounts_phone_number_unique');
            $table->timestamp('phone_verified_at')->nullable()->comment('Set when a verification fee was paid from phone_number');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->nullable()->after('user_id')->comment('The merchant that owns this shop');
            $table->foreign('merchant_id', 'shops_merchant_id_foreign')->references('id')->on('merchants')->nullOnDelete();
        });

        $now = now();

        DB::table('users')->where('user_type', 'merchant')->orderBy('id')->get()->each(function ($user) use ($now) {
            $firstShop = DB::table('shops')->where('user_id', $user->id)->whereNull('deleted_at')->orderBy('id')->first();
            $phone = $user->phone_number ?? $firstShop?->phone_number;
            $isGeneratedEmail = $user->email && str_ends_with($user->email, '@email.com');

            $merchantId = DB::table('merchants')->insertGetId([
                'user_id' => $user->id,
                'first_name' => $user->first_name ?? $firstShop?->first_name,
                'last_name' => $user->last_name ?? $firstShop?->last_name,
                'dob' => $user->dob ?? $firstShop?->dob,
                'email' => $isGeneratedEmail ? null : $user->email,
                'phone_number' => $phone && ! DB::table('merchants')->where('phone_number', $phone)->exists() ? $phone : null,
                'phone_verified_at' => $user->phone_verified_at,
                'created_at' => $user->created_at ?? $now,
                'updated_at' => $now,
            ]);

            DB::table('shops')->where('user_id', $user->id)->update(['merchant_id' => $merchantId]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_number']);
            $table->dropColumn(['phone_number', 'phone_verified_at', 'first_name', 'last_name', 'dob']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number', 20)->nullable()->unique()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone_number');
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->date('dob')->nullable()->after('last_name');
        });

        DB::table('merchants')->orderBy('id')->get()->each(fn ($merchant) => DB::table('users')->where('id', $merchant->user_id)->update([
            'phone_number' => $merchant->phone_number,
            'phone_verified_at' => $merchant->phone_verified_at,
            'first_name' => $merchant->first_name,
            'last_name' => $merchant->last_name,
            'dob' => $merchant->dob,
        ]));

        Schema::table('shops', function (Blueprint $table) {
            $table->dropForeign('shops_merchant_id_foreign');
            $table->dropColumn('merchant_id');
        });

        Schema::drop('merchants');

        DB::statement('CREATE VIEW merchants AS SELECT * FROM shops');
    }
};
