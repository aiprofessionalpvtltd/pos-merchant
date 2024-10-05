<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeePermission extends Model
{
    // If you want to customize the table name
    protected $table = 'employee_permissions';

    protected $fillable = [
        'employee_id',
        'pos_permission_id',
    ];

    // Define relationships with Employee and Permission models
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function permission()
    {
        return $this->belongsTo(POSPermission::class ,'pos_permission_id');
    }
}
