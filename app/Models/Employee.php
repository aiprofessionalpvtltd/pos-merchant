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
}
