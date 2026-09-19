<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Salary and location are optional in v1
            $table->decimal('salary', 10, 2)->nullable()->change();
            $table->string('location')->nullable()->change();

            $table->string('salary_currency', 4)->default('SLSH')->comment('Currency of salary; legacy salaries are SLSH');
            $table->enum('salary_period', ['hourly', 'daily', 'monthly'])->default('daily');
            $table->timestamp('removed_at')->nullable()->comment('Set when staff are removed; history is kept');
            $table->string('former_phone_number')->nullable()->comment('Number before removal, which frees it for a new account');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete()->comment('Merchant who corrected this shift');
            $table->timestamp('edited_at')->nullable();
            $table->string('edit_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edited_by');
            $table->dropColumn(['edited_at', 'edit_reason']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['salary_currency', 'salary_period', 'removed_at', 'former_phone_number']);
        });
    }
};
