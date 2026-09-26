<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One person may work in several shops (docs/employees.md, "Staff in several shops"):
     *
     * - A shift belongs to the shop it was worked in (`shifts.merchant_id`, a shop id),
     *   so hours and payroll are counted per shop. Existing shifts get the shop of the
     *   person's staff record, or an owner's first shop.
     * - One staff record per person per shop (`employees(user_id, merchant_id)` unique).
     */
    public function up(): void
    {
        $duplicates = DB::table('employees')->whereNotNull('user_id')
            ->select('user_id', 'merchant_id')->groupBy('user_id', 'merchant_id')->havingRaw('count(*) > 1')->count();

        if ($duplicates > 0) {
            throw new RuntimeException("{$duplicates} person/shop pairs have more than one staff record. Resolve them before migrating.");
        }

        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->nullable()->after('user_id')->comment('The shop the shift was worked in (a shops.id)');
            $table->foreign('merchant_id', 'shifts_shop_foreign')->references('id')->on('shops')->nullOnDelete();
            $table->index(['merchant_id', 'user_id', 'start_time'], 'shifts_shop_user_start_index');
        });

        // Staff: their staff record's shop. Owners: their first shop.
        DB::statement('UPDATE shifts s JOIN (SELECT user_id, MIN(merchant_id) AS shop_id FROM employees WHERE user_id IS NOT NULL GROUP BY user_id) e ON e.user_id = s.user_id SET s.merchant_id = e.shop_id WHERE s.merchant_id IS NULL');
        DB::statement('UPDATE shifts s JOIN (SELECT user_id, MIN(id) AS shop_id FROM shops WHERE user_id IS NOT NULL AND deleted_at IS NULL GROUP BY user_id) o ON o.user_id = s.user_id SET s.merchant_id = o.shop_id WHERE s.merchant_id IS NULL');

        Schema::table('employees', function (Blueprint $table) {
            $table->unique(['user_id', 'merchant_id'], 'employees_person_shop_unique');
        });
    }

    public function down(): void
    {
        // The unique index also serves the employees.user_id foreign key, so give that key
        // its own index before dropping it.
        if (! Schema::hasIndex('employees', 'employees_user_id_index')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->index('user_id', 'employees_user_id_index');
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_person_shop_unique');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign('shifts_shop_foreign');
            $table->dropIndex('shifts_shop_user_start_index');
            $table->dropColumn('merchant_id');
        });
    }
};
