<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class POSPermission extends Model
{
     use HasFactory;

    protected $fillable = ['name'];
    protected $table = 'pos_permissions';

    // Relationship to Employees
    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'employee_permissions');
    }


}
