<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'start_time', 'end_time', 'edited_by', 'edited_at', 'edit_reason'];

    protected $casts = [
        'edited_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /**
     * A shift is open while it has a start and no end.
     */
    public function scopeOpen($query)
    {
        return $query->whereNotNull('start_time')->whereNull('end_time');
    }
}
