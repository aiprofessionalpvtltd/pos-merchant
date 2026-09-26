<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every shop stores its own full set of receipt, register and alert preferences in
     * `shops.preferences`. Until now a shop stored only what it had changed and the rest
     * came from config `exelo.preference_defaults`. This fills in the missing values for all
     * existing shops (including closed ones), keeping anything a shop already set.
     */
    public function up(): void
    {
        $defaults = config('exelo.preference_defaults');

        DB::table('shops')->orderBy('id')->get(['id', 'preferences'])->each(function ($shop) use ($defaults) {
            $stored = $shop->preferences ? json_decode($shop->preferences, true) : [];

            DB::table('shops')->where('id', $shop->id)->update([
                'preferences' => json_encode(array_replace_recursive($defaults, is_array($stored) ? $stored : [])),
            ]);
        });
    }

    /**
     * Back to storing only what differs from the defaults.
     */
    public function down(): void
    {
        $defaults = config('exelo.preference_defaults');

        DB::table('shops')->orderBy('id')->get(['id', 'preferences'])->each(function ($shop) use ($defaults) {
            $stored = $shop->preferences ? json_decode($shop->preferences, true) : [];
            $overrides = [];

            foreach ($defaults as $group => $values) {
                foreach ($values as $key => $default) {
                    if (array_key_exists($key, $stored[$group] ?? []) && $stored[$group][$key] !== $default) {
                        $overrides[$group][$key] = $stored[$group][$key];
                    }
                }
            }

            DB::table('shops')->where('id', $shop->id)->update(['preferences' => $overrides ? json_encode($overrides) : null]);
        });
    }
};
