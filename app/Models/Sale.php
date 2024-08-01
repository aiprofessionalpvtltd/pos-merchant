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
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
