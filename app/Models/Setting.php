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
        'registration_fee',
        'registration_fee_charge',
        'verification_fee',
        'verification_fee_charge',
        'sales_fee_percent',
    ];

    protected $casts = [
        'registration_fee' => 'integer',
        'registration_fee_charge' => 'integer',
        'verification_fee' => 'integer',
        'verification_fee_charge' => 'integer',
        'sales_fee_percent' => 'decimal:2',
    ];

    public static function getSetting()
    {
        return Setting::first();
    }
}
