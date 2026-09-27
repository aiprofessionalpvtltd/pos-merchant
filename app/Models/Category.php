<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'shop_id', 'merchant_id'];

    protected static function booted(): void
    {
        static::creating(function (Category $category) {
            if ($category->shop_id && ! $category->merchant_id) {
                $category->merchant_id = Shop::whereKey($category->shop_id)->value('merchant_id');
            }
        });
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    // Legacy name: the shop this category belongs to (see docs/data-model.md).
    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'shop_id');
    }

    /**
     * The shop this category belongs to (`categories.shop_id`).
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * The merchant that owns that shop (`categories.merchant_id`).
     */
    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class, 'merchant_id');
    }
}
