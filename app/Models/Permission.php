<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    use HasFactory;


    // Permission.php
    public function module()
    {
        return $this->belongsTo(Module::class,'name');
    }
}
