<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'order_status', // 'pending', 'completed', etc.
        'total_price',
        'total_price',
        'vat',
        'exelo_amount',
        'sub_total',
        'order_type', // 'shop' or 'stock'
    ];

    // Relationship with OrderItem
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
