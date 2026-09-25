<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('merchant_id')->nullable()->after('tokenable_id')
                ->constrained('merchants')->nullOnDelete()
                ->comment('The shop this token acts for; null acts for the owner\'s first shop (tokens issued before multi-shop)');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merchant_id');
        });
    }
};
