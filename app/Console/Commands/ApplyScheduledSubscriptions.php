<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ApplyScheduledSubscriptions extends Command
{
    protected $signature = 'subscriptions:apply-scheduled';

    protected $description = 'Move merchants to their scheduled plan once the current period has ended';

    public function handle(SubscriptionService $subscriptions): int
    {
        $checked = $subscriptions->applyAllScheduled();

        $this->info("Checked {$checked} merchant(s) with a scheduled plan change.");

        return self::SUCCESS;
    }
}
