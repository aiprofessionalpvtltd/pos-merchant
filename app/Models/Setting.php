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
    ];

    protected $casts = [
        'registration_fee' => 'integer',
        'registration_fee_charge' => 'integer',
        'verification_fee' => 'integer',
        'verification_fee_charge' => 'integer',
    ];

    public static function getSetting()
    {
        return Setting::first();
    }
}
