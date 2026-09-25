<?php

namespace App\Models;

use App\Observers\InvoiceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(InvoiceObserver::class)]
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
        'subscription_plan_id',
        'expires_at',
        'paid_at',
        'consumed_at',
        'error_reason',
        'purpose',
        'cart_id',
        'cart_version',
        'meta',
        'order_id',
        'user_id',
    ];

    protected $casts = [
        'meta' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public static function generatePublicId(): string
    {
        return 'inv_'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::ulid()->toBase32());
    }

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

    /**
     * The customer turned the wallet prompt down. The payment is still pending: eDahab keeps the invoice open.
     */
    public function isPromptDeclined(): bool
    {
        return ($this->meta['provider_prompt'] ?? null) === 'declined';
    }

    /**
     * Meta to store for a freshly issued wallet invoice.
     *
     * @param  array{prompt?: ?string}  $issued
     */
    public static function issuedMeta(array $issued): array
    {
        return isset($issued['prompt']) ? ['provider_prompt' => $issued['prompt']] : [];
    }

    /**
     * Every eDahab / WaafiPay call made for this payment, oldest first.
     */
    public function apiLogs(): HasMany
    {
        return $this->hasMany(ApiLog::class)->oldest('id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
