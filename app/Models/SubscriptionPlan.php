<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SubscriptionPlan extends Model
{
    use HasFactory, SoftDeletes;

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

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }
}
