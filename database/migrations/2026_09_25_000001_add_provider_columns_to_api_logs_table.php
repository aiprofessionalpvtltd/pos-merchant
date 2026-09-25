<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->integer('status_code')->nullable()->comment('HTTP status; null when the call never got a response')->change();

            $table->string('provider', 20)->nullable()->after('id')->index()->comment('edahab or waafi');
            $table->string('operation', 50)->nullable()->after('provider')->comment('eDahab endpoint or WaafiPay serviceName');
            $table->foreignId('invoice_id')->nullable()->after('operation')->constrained('invoices')->nullOnDelete();
            $table->string('our_reference', 100)->nullable()->after('invoice_id')->index()->comment('Id we sent: eDahab transactionId, WaafiPay referenceId');
            $table->string('provider_reference', 100)->nullable()->after('our_reference')->index()->comment('Id the provider returned, e.g. MP… / PP… or the WaafiPay transactionId');
            $table->string('provider_status')->nullable()->after('provider_reference')->comment('Outcome as the provider reported it');
            $table->unsignedInteger('duration_ms')->nullable()->after('response_body');
            $table->text('error')->nullable()->after('duration_ms')->comment('Why the call failed when there was no usable response');
        });
    }

    public function down(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropIndex(['provider']);
            $table->dropIndex(['our_reference']);
            $table->dropIndex(['provider_reference']);
            $table->dropColumn(['provider', 'operation', 'our_reference', 'provider_reference', 'provider_status', 'duration_ms', 'error']);
            $table->integer('status_code')->nullable(false)->change();
        });
    }
};
