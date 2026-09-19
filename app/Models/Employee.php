<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'merchant_id',
        'phone_number',
        'first_name',
        'last_name',
        'dob',
        'location',
        'role',
        'salary',
        'salary_currency',
        'salary_period',
        'removed_at',
        'former_phone_number',
        'status',
    ];

    protected $casts = [
        'removed_at' => 'datetime',
    ];

    // Relationship to User
    public function user()
    {
        return $this->belongsTo(User::class);
    }
 // Relationship to Merchant
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    // Relationship to Permissions
    public function permissions()
    {
        return $this->hasMany(EmployeePermission::class, 'employee_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
