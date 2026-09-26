<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shop: stock, sales, staff, plan and payout wallets. One merchant account (a User
 * with user_type "merchant") owns many shops.
 *
 * Stored in the `shops` table. Every other table points at it with a `merchant_id`
 * column, a name that predates multi-shop. New code uses Shop; the legacy Merchant
 * model is the same table. See docs/data-model.md.
 */
class Shop extends Merchant
{
    protected $table = 'shops';

    /**
     * Related tables point at a shop through their `merchant_id` column.
     */
    public function getForeignKey(): string
    {
        return 'merchant_id';
    }

    /**
     * The merchant that owns this shop (table `merchants`).
     */
    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class, 'merchant_id');
    }

    /**
     * The owner's sign-in (table `users`).
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'merchant_id');
    }

    public function activeEmployees(): HasMany
    {
        return $this->employees()->where('status', 'active');
    }
}
