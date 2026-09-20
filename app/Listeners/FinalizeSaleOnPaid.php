<?php

namespace App\Listeners;

use App\Events\InvoicePaid;
use App\Services\ChargeService;

class FinalizeSaleOnPaid
{
    public function __construct(private readonly ChargeService $charges) {}

    public function handle(InvoicePaid $event): void
    {
        $this->charges->finalize($event->invoice);
    }
}
