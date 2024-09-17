<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Merchant extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'first_name',
        'last_name',
        'dob',
        'location',
        'business_name',
        'merchant_code',
        'email',
        'phone_number',
        'is_approved',
        'confirmation_status',
        'otp',
        'otp_expires_at',
        'user_id'
    ];


    protected static function boot()
    {
        parent::boot();

        static::creating(function ($merchant) {
//            $merchant->merchant_id = 'MER' . strtoupper(Str::random(10));
//            $merchant->iccid_number = self::generateIccidNumber();
        });
    }

    public static function generateIccidNumber()
    {
        // Generate a random 19 or 20 digit ICCID number using string manipulation
        $length = rand(19, 20);  // Choose between 19 or 20 digits
        $iccid_number = '';

        for ($i = 0; $i < $length; $i++) {
            $iccid_number .= mt_rand(0, 9);
        }

        return $iccid_number;
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withDefault();
    }

    public function subscriptions()
    {
        return $this->hasMany(MerchantSubscription::class);
    }
    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    // Relationship with Cart
    public function carts()
    {
        return $this->hasMany(Cart::class);
    }

    // Relationship with Orders
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function subscription()
    {
        return $this->hasOne(MerchantSubscription::class);
    }

    public function currentSubscription()
    {
        return $this->hasOne(MerchantSubscription::class)
            ->where('is_canceled', false) // Only active (not canceled) subscription
            ->latest(); // Get the most recent active subscription
    }

    public function canceledSubscriptions()
    {
        return $this->hasMany(MerchantSubscription::class)
            ->where('is_canceled', true); // Only canceled subscriptions
    }


}
