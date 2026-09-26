<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shops get their own table (docs/data-model.md). The `merchants` table always held
     * one row per shop, so it is renamed to `shops`: same rows, same ids, no copying.
     * Foreign keys from the other tables' `merchant_id` columns follow the rename.
     *
     * A `merchants` VIEW over `shops` keeps the legacy API's raw `merchants` queries
     * working. It is `SELECT *`, so a later migration that changes the columns of
     * `shops` must recreate it (see the note in docs/data-model.md).
     */
    public function up(): void
    {
        Schema::rename('merchants', 'shops');

        DB::statement('CREATE VIEW merchants AS SELECT * FROM shops');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS merchants');

        Schema::rename('shops', 'merchants');
    }
};
