<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{

    protected $fillable = [
        'company_name',
        'company_email',
        'address_one',
        'sub_charges',
        'invoice_prefix',

    ];

    public static function getSetting()
    {
        return Setting::first();
    }
}
