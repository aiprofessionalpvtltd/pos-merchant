<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MerchantSubscription extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'subscription_plan_id',
        'start_date',
        'end_date',
        'transaction_status',
        'is_canceled',
        'canceled_at',
        'next_plan_id',
        'cancel_reason',
        'cancel_comment',
        'invoice_id',
    ];

    protected $casts = [
        'is_canceled' => 'boolean',
        'canceled_at' => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function subscriptionPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function nextPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'next_plan_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
