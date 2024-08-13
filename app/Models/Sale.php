<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'amount',
        'payment_method',
        'is_completed',
        'is_successful',
        'total_customer_charge',
        'total_customer_charge_usd',
        'amount_sent_to_exelo',
        'amount_sent_to_exelo_usd',
        'merchant_receives',
        'merchant_receives_usd',
        'zaad_fee_from_exelo',
        'zaad_fee_from_exelo_usd',
        'zaad_fee',
        'zaad_fee_usd',
        'conversion_rate',
    ];


    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
