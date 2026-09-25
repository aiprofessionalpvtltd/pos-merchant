<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SubscriptionPlan extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Every feature a plan can grant. The app gates screens on these keys, so they are fixed here rather than typed in.
     */
    public const FEATURES = [
        'dashboard.basic' => 'Basic dashboard',
        'dashboard.full' => 'Full dashboard',
        'payments.request' => 'Request payments',
        'pos.register' => 'POS register',
        'pos.scanning' => 'Barcode scanning',
        'inventory.read' => 'View inventory',
        'inventory.write' => 'Edit inventory',
        'inventory.transfers' => 'Stock transfers',
        'employees.manage' => 'Manage employees',
        'reports.full' => 'Full reports',
        'nfc.payments' => 'NFC payments',
        'offline.mode' => 'Offline mode',
    ];

    /**
     * Every plan is billed for one month at a time (SubscriptionService adds one month per payment).
     */
    public const DURATION = 'monthly';

    protected $fillable = ['name', 'price', 'duration', 'key', 'features', 'is_default', 'price_slsh'];

    protected $casts = [
        'features' => 'array',
        'is_default' => 'boolean',
        'price_slsh' => 'integer',
    ];

    protected static function booted(): void
    {
        // Plans created from the admin panel get a key without extra input.
        static::creating(function (SubscriptionPlan $plan) {
            $plan->key ??= Str::slug(Str::before($plan->name, ' Package'));
        });
    }

    public function scopeOffered($query)
    {
        return $query->whereNotNull('key');
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function merchantSubscriptions(): HasMany
    {
        return $this->hasMany(MerchantSubscription::class);
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }
}
