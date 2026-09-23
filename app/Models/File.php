<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class File extends Model
{
    protected $fillable = [
        'public_id',
        'merchant_id',
        'purpose',
        'disk',
        'path',
        'thumb_path',
        'content_type',
        'bytes',
        'width',
        'height',
        'client_uuid',
        'attached_at',
    ];

    protected $casts = [
        'attached_at' => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function thumbUrl(): ?string
    {
        return $this->thumb_path ? Storage::disk($this->disk)->url($this->thumb_path) : null;
    }
}
