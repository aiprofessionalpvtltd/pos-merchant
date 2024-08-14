<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'amount',
        'payment_method',
        'transaction_id',
        'is_successful',
        'amount_to_merchant',
        'amount_to_exelo',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

}
