<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('key')->nullable()->unique()->comment('Stable identifier clients gate on: gold, silver');
            $table->json('features')->nullable()->comment('Feature keys the plan grants');
            $table->boolean('is_default')->default(false)->comment('Plan a merchant falls back to; never expires');
            $table->unsignedInteger('price_slsh')->nullable()->comment('Monthly price in SLSH; price stays the USD price');
        });

        Schema::table('merchant_subscriptions', function (Blueprint $table) {
            $table->date('end_date')->nullable()->change();
            $table->foreignId('next_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete()->comment('Plan that starts when this one ends (scheduled downgrade)');
            $table->string('cancel_reason')->nullable();
            $table->text('cancel_comment')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete()->comment('Invoice that paid for this period');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete()->comment('Plan a Subscription invoice pays for');
        });

        DB::table('subscription_plans')->where('name', 'like', 'Gold%')->update(['key' => 'gold']);
        DB::table('subscription_plans')->where('name', 'like', 'Silver%')->update(['key' => 'silver', 'is_default' => true]);

        $silverId = DB::table('subscription_plans')->where('key', 'silver')->value('id');

        if ($silverId) {
            DB::table('merchant_subscriptions')->where('subscription_plan_id', $silverId)->update(['end_date' => null]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_plan_id');
        });

        DB::table('merchant_subscriptions')->whereNull('end_date')->update(['end_date' => DB::raw('start_date')]);

        Schema::table('merchant_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('next_plan_id');
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn(['cancel_reason', 'cancel_comment']);
            $table->date('end_date')->nullable(false)->change();
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['key', 'features', 'is_default', 'price_slsh']);
        });
    }
};
