<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class POSPermission extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    protected $table = 'pos_permissions';

    private const KEYS = [
        'POS' => 'pos',
        'Inventory' => 'inventory',
        'Transactions' => 'transactions',
        'Employee Management' => 'employees',
        'Reports' => 'reports',
    ];

    public const DESCRIPTIONS = [
        'pos' => 'Use the register and take payment',
        'inventory' => 'Add and edit products, move stock',
        'transactions' => 'See orders and receipts',
        'employees' => 'Add and manage staff',
        'reports' => 'See sales and inventory reports',
    ];

    public function getDescriptionAttribute(): ?string
    {
        return self::DESCRIPTIONS[$this->permission_key] ?? null;
    }

    /**
     * Stable identifier clients authorise on; `name` is display only.
     */
    public function getPermissionKeyAttribute(): string
    {
        return self::KEYS[$this->name] ?? \Illuminate\Support\Str::slug($this->name, '_');
    }

    // Relationship to Employees
    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'employee_permissions');
    }
}
