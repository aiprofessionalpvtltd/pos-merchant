<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
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

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if ($order->shop_id && ! $order->merchant_id) {
                $order->merchant_id = Shop::whereKey($order->shop_id)->value('merchant_id');
            }
        });
    }

    // Legacy name: the shop this order belongs to (see docs/data-model.md).
    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'shop_id');
    }

    /**
     * The shop this order belongs to (`orders.shop_id`).
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * The merchant that owns that shop (`orders.merchant_id`).
     */
    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class, 'merchant_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * The invoice that actually paid for this order — the settled one, even if an
     * earlier attempt on the same order was declined or expired first. Its
     * `currency` is what the customer really paid in (see docs/orders.md).
     */
    public function paidInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->where('status', 'Paid')->latestOfMany('id');
    }
}
