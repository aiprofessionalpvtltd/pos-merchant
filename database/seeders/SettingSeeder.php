<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Setting::create([

            'company_name' => 'ABC',
            'company_email' => 'ask@ABC.com',
            'company_website' => 'www.ABC.com',
            'phone_no' => '03333332222',
            'address_one' => 'Islamabad',
            'logo' => '',
        ]);
    }
}
