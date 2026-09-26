<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * Not stored: set by EmployeeService::create() when an existing staff member from another
     * shop was added, so the response can say they keep their PIN.
     */
    public bool $joinedAsExistingPerson = false;

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

    /**
     * The shop this employee works in (`employees.merchant_id` holds a shop id).
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'merchant_id');
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
