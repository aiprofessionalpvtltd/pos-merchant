<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A merchant: the person who owns shops. Table `merchants`; one merchant has many
 * shops (`shops.merchant_id`). Signs in through its `users` row (PIN, tokens).
 *
 * Named MerchantAccount because the legacy App\Models\Merchant class is a shop
 * (table `shops`). See docs/data-model.md.
 */
class MerchantAccount extends Model
{
    use SoftDeletes;

    protected $table = 'merchants';

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'dob',
        'email',
        'phone_number',
        'phone_verified_at',
    ];

    protected $casts = [
        'phone_verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class, 'merchant_id');
    }

    /**
     * The staff of all this merchant's shops (merchants → shops → employees).
     */
    public function employees(): HasManyThrough
    {
        return $this->hasManyThrough(Employee::class, Shop::class, 'merchant_id', 'merchant_id');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isPhoneVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }
}
