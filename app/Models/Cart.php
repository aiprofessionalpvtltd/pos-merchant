<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'cart_type', // 'shop' or 'stock'
    ];

    // Relationship with CartItem
    public function items()
    {
        return $this->hasMany(CartItem::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
