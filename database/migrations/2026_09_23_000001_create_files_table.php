<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 20)->comment('product_image, signature or merchant_logo');
            $table->string('disk', 20)->default('public');
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->string('content_type', 100);
            $table->unsignedInteger('bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('client_uuid', 64)->nullable()->comment('Dedupes a retried upload from the same device');
            $table->timestamp('attached_at')->nullable()->comment('Set once a product, order or merchant references this file; unattached files older than 24h are swept');
            $table->timestamps();

            $table->unique(['merchant_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
