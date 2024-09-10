<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductInventory extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'quantity',
        'type'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Relationship with CartItem
    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    // Relationship with OrderItem
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
}
