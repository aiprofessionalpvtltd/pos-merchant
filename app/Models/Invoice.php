<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'invoices';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'merchant_id',
        'invoice_id',
        'mobile_number',
        'first_name',
        'last_name',
        'transaction_id',
        'hash',
        'amount',
        'currency',
        'status',
        'e_transaction_id',
        'type',
        'payment_method',
        'public_id',
        'rail',
        'wallet_number',
        'expires_at',
        'paid_at',
        'consumed_at',
        'error_reason',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function scopePaid($query)
    {
        return $query->where('status', 'Paid');
    }

    public function order()
    {
        return $this->belongsTo(Order::class)->withDefault();
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class)->withDefault();
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
