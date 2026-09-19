<?php

namespace App\Listeners;

use App\Events\InvoicePaid;
use App\Services\SubscriptionService;

class ApplySubscriptionOnPaid
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function handle(InvoicePaid $event): void
    {
        if ($event->invoice->type === 'Subscription') {
            $this->subscriptions->applyPaidInvoice($event->invoice);
        }
    }
}
