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

        'total_amount_after_conversion',
        'amount_to_merchant',
        'conversion_fee_amount',
        'transaction_fee_amount',
        'total_fee_charge_to_customer',
        'amount_sent_to_exelo',
        'total_amount_charge_to_customer',
        'conversion_rate',
        'currency',

     ];


    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
