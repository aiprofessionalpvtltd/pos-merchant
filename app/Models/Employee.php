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
        'shop_id',
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

    protected static function booted(): void
    {
        static::creating(function (Employee $employee) {
            if ($employee->shop_id && ! $employee->merchant_id) {
                $employee->merchant_id = Shop::whereKey($employee->shop_id)->value('merchant_id');
            }
        });
    }

    // Relationship to User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Legacy name: the shop this employee works in (see docs/data-model.md).
    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'shop_id');
    }

    /**
     * The merchant that owns the shop this employee works in (employees.merchant_id).
     */
    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class, 'merchant_id');
    }

    /**
     * The shop this employee works in (`employees.shop_id`).
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
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
