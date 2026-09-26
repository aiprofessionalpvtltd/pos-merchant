<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A worked shift of one person in one shop (`merchant_id` holds the shop id).
 */
class Shift extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'merchant_id', 'start_time', 'end_time', 'edited_by', 'edited_at', 'edit_reason'];

    protected $casts = [
        'edited_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Shifts created without a shop (e.g. by the legacy API) belong to the person's shop:
        // the shop of their session if known, else their first staff record, else their first owned shop.
        static::creating(function (Shift $shift) {
            if ($shift->merchant_id || ! $shift->user_id) {
                return;
            }

            $user = User::withTrashed()->find($shift->user_id);

            $shift->merchant_id = $user?->actingMerchant()?->id
                ?? Employee::where('user_id', $shift->user_id)->orderByRaw("status = 'active' desc")->orderBy('id')->value('shop_id')
                ?? Shop::where('user_id', $shift->user_id)->orderBy('id')->value('id');
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'merchant_id');
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

    public function scopeForShop($query, int $shopId)
    {
        return $query->where('merchant_id', $shopId);
    }
}
