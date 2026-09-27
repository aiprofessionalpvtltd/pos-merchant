<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_name',
        'category_id',
        'shop_id',
        'merchant_id',
        'price',
        'price_sls',
        'exchange_rate',
        'vat',
        'total_price',
        'stock_limit',
        'alarm_limit',
        'image',
        'bar_code',
        'version',
        'client_uuid',
        'image_file_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if ($product->shop_id && ! $product->merchant_id) {
                $product->merchant_id = Shop::whereKey($product->shop_id)->value('merchant_id');
            }
        });
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // Legacy name: the shop this product belongs to (see docs/data-model.md).
    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'shop_id');
    }

    /**
     * The shop this product belongs to (`products.shop_id`).
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * The merchant that owns that shop (`products.merchant_id`).
     */
    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class, 'merchant_id');
    }

    public function inventories()
    {
        return $this->hasMany(ProductInventory::class);
    }

    public function history()
    {
        return $this->hasMany(InventoryHistory::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
}
