<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;
    protected $fillable = [
        'transaction_amount',
        'transaction_status',
        'transaction_message',
        'phone_number',
        'transaction_id',
        'merchant_id',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
