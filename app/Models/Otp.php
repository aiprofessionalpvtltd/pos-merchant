<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',  // Foreign key to the users table
        'otp',      // The OTP code
        'expires_at', // Expiration time of the OTP
    ];
}
