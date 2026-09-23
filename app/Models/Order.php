<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'user_id',
        'order_status', // 'pending', 'completed', etc.
        'total_price',
        'name',
        'mobile_number',
        'signature',
        'total_price',
        'total_price_sls',
        'exchange_rate',
        'vat',
        'exelo_amount',
        'sub_total',
        'order_type', // 'shop' or 'stock'
        'version',
        'paid_at',
        'payment_method',
        'note',
        'client_order_id',
        'cancel_reason',
        'stock_deducted_at',
        'signature_file_id',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'stock_deducted_at' => 'datetime',
    ];

    public function isPending(): bool
    {
        return strtolower($this->order_status) === 'pending';
    }

    public function isCancelled(): bool
    {
        return strtolower($this->order_status) === 'cancelled';
    }

    /**
     * The legacy app wrote "Paid" for a sold order; v1 shows it as Complete.
     */
    public function isComplete(): bool
    {
        return in_array(strtolower($this->order_status), ['complete', 'paid'], true);
    }

    // Relationship with OrderItem
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }
}
