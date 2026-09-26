<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff are listed and counted per shop and status (docs/employees.md, "Staff across shops").
     * `employees.merchant_id` holds a shop id (docs/data-model.md).
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->index(['merchant_id', 'status'], 'employees_shop_status_index');
        });
    }

    public function down(): void
    {
        // This index also serves the employees.merchant_id foreign key: give that key its own first.
        if (! Schema::hasIndex('employees', 'employees_merchant_id_index')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->index('merchant_id', 'employees_merchant_id_index');
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex('employees_shop_status_index');
        });
    }
};
