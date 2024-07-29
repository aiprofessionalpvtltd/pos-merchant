<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Merchant extends Model
{
    protected $fillable = [
        'name', 'address', 'country', 'city', 'state', 'phone_number', 'approved', 'user_id' ,'surename','merchant_id','iccid_number'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($merchant) {
            $merchant->merchant_id = 'MER' . strtoupper(Str::random(10));
            $merchant->iccid_number = self::generateIccidNumber();
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
        return $this->belongsTo(User::class);
    }


}
