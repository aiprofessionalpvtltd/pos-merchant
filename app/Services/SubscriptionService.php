<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\SubscriptionState;
use App\Support\ApiResponse;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SubscriptionService
{
    public function __construct(private readonly WalletGateway $gateway) {}

    /**
     * @return Collection<int, SubscriptionPlan>
     */
    public function plans(): Collection
    {
        return SubscriptionPlan::offered()->orderBy('price_slsh')->orderBy('id')->get();
    }

    public function defaultPlan(): SubscriptionPlan
    {
        $plan = SubscriptionPlan::default()->orderBy('id')->first()
            ?? SubscriptionPlan::find(config('exelo.default_subscription_plan_id'));

        return $plan ?? throw new RuntimeException('No default subscription plan is configured');
    }

    public static function planName(SubscriptionPlan $plan): string
    {
        return Str::ucfirst($plan->key ?? Str::before($plan->name, ' '));
    }

    /**
     * The merchant's subscription as of now. A merchant with no row is on the
     * default plan; reading never creates a row.
     */
    public function state(Merchant $merchant): SubscriptionState
    {
        $this->applyScheduledFor($merchant);

        return $this->buildState($this->currentRow($merchant), $this->plans(), $this->defaultPlan());
    }

    /**
     * Read-only: works out the state from a row the caller already loaded, so a
     * list of merchants needs no extra queries and writes nothing.
     *
     * @param  Collection<int, SubscriptionPlan>  $plans
     */
    public function buildState(?MerchantSubscription $row, Collection $plans, SubscriptionPlan $default): SubscriptionState
    {
        $plan = $row?->subscriptionPlan ?? $default;
        $end = $row?->end_date ? Carbon::parse($row->end_date)->endOfDay() : null;
        $grace = null;

        if ($plan->is_default || $end === null) {
            $status = 'active';
        } elseif ($end->isFuture()) {
            $status = $row->is_canceled ? 'cancelled' : 'active';
        } else {
            $graceEnds = $end->copy()->addDays(config('exelo.subscription.grace_days'))->endOfDay();

            if (! $row->is_canceled && now()->lte($graceEnds)) {
                $status = 'grace';
                $grace = ['ends_at' => $graceEnds, 'days_remaining' => max(0, (int) ceil(now()->diffInHours($graceEnds, false) / 24))];
            } else {
                $status = 'expired';
            }
        }

        $effective = $status === 'expired' ? $default : $plan;
        $effectivePrice = (int) $effective->price_slsh;

        $daysLeft = $end ? (int) ceil(now()->diffInHours($end, false) / 24) : null;
        $canRenew = ! $plan->is_default && $end !== null
            && ($status !== 'active' || $daysLeft <= config('exelo.subscription.renew_window_days'));

        $canUpgrade = $plans->contains(fn (SubscriptionPlan $offered) => (int) $offered->price_slsh > $effectivePrice);
        $canDowngrade = in_array($status, ['active', 'grace'], true)
            && ! $plan->is_default
            && ! $row?->next_plan_id
            && $plans->contains(fn (SubscriptionPlan $offered) => (int) $offered->price_slsh < $effectivePrice);

        return new SubscriptionState(
            plan: $plan,
            effectivePlan: $effective,
            nextPlan: $row?->nextPlan,
            defaultPlan: $default,
            row: $row,
            status: $status,
            features: $effective->features ?? [],
            startedAt: $row?->start_date ? Carbon::parse($row->start_date)->startOfDay() : null,
            expiresAt: $plan->is_default ? null : $end,
            grace: $grace,
            canUpgrade: $canUpgrade,
            canDowngrade: $canDowngrade,
            canRenew: $canRenew,
            message: $this->stateMessage($plan, $effective, $status, $end, $grace),
        );
    }

    /**
     * Upgrade or renew (returns an invoice to pay) or schedule a downgrade.
     *
     * @return array{status: int, message: string, data: array}
     */
    public function change(Merchant $merchant, int $planId, ?string $rail, ?string $walletNumber, string $idempotencyKey): array
    {
        $state = $this->state($merchant);
        $target = $this->plans()->firstWhere('id', $planId);

        if (! $target) {
            throw new ApiException('subscription.plan_unavailable', 'That plan is not available', 422, [], 'plan_id');
        }

        $replayKey = $this->idempotencyCacheKey($merchant, $idempotencyKey);
        $replay = Cache::get($replayKey);

        if ($replay && ($invoice = Invoice::find($replay))) {
            return $this->replayPayload($invoice, $target);
        }

        $isRenewal = $target->id === $state->plan->id && $state->canRenew;

        if ($target->id === $state->effectivePlan->id && ! $isRenewal) {
            throw new ApiException('subscription.already_on_plan', 'You are already on the '.self::planName($target).' package', 409);
        }

        if ((int) $target->price_slsh < (int) $state->effectivePlan->price_slsh) {
            return $this->scheduleDowngrade($merchant, $state, $target);
        }

        return $this->startPayment($merchant, $target, $isRenewal, $rail, $walletNumber, $replayKey);
    }

    /**
     * Cancels at period end; the merchant keeps the plan until it expires.
     *
     * @return array{message: string, data: array{status: string, access_until: string, reverts_to: string, resubscribe_eligible: bool}}
     */
    public function cancel(Merchant $merchant, ?string $reason, ?string $comment): array
    {
        $state = $this->state($merchant);

        if ($state->isDefaultPlan() || ! $state->row || in_array($state->status, ['grace', 'expired'], true)) {
            throw new ApiException('subscription.nothing_to_cancel', 'There is no paid plan to cancel', 409);
        }

        if ($state->row->is_canceled) {
            throw new ApiException('subscription.already_cancelled', 'Your plan is already cancelled', 409);
        }

        $state->row->update([
            'is_canceled' => true,
            'canceled_at' => now(),
            'cancel_reason' => $reason,
            'cancel_comment' => $comment,
            'next_plan_id' => null,
        ]);

        return [
            'message' => 'Cancelled. You keep '.self::planName($state->plan).' until '.$state->expiresAt->format('j F').'.',
            'data' => [
                'status' => 'cancelled',
                'access_until' => ApiResponse::iso($state->expiresAt),
                'reverts_to' => $state->defaultPlan->key,
                'resubscribe_eligible' => true,
            ],
        ];
    }

    /**
     * Starts (or extends) the plan a paid Subscription invoice was for. Safe to
     * call more than once: the invoice is consumed exactly once.
     */
    public function applyPaidInvoice(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $locked = Invoice::lockForUpdate()->find($invoice->id);

            if (! $locked || $locked->type !== 'Subscription' || $locked->status !== 'Paid' || $locked->consumed_at || ! $locked->merchant_id) {
                return;
            }

            $merchant = Merchant::find($locked->merchant_id);
            $plan = SubscriptionPlan::find($locked->subscription_plan_id);

            if (! $merchant || ! $plan) {
                return;
            }

            $current = $this->currentRow($merchant);
            $today = now()->startOfDay();
            $periodStart = $today;

            // Renewing a plan that has not lapsed continues from its end date.
            if ($current && (int) $current->subscription_plan_id === $plan->id && $current->end_date && Carbon::parse($current->end_date)->isFuture()) {
                $periodStart = Carbon::parse($current->end_date)->startOfDay();
            }

            MerchantSubscription::create([
                'merchant_id' => $merchant->id,
                'subscription_plan_id' => $plan->id,
                'start_date' => $today,
                'end_date' => $periodStart->copy()->addMonth(),
                'transaction_status' => 'Paid',
                'invoice_id' => $locked->id,
            ]);

            $locked->update(['consumed_at' => now(), 'merchant_id' => $merchant->id]);
        });
    }

    /**
     * Admin override of a shop's current subscription (support cases, cash arranged
     * outside the app). Only the newest row can be changed, and a paid plan always
     * needs an end date, so it can never run forever by accident.
     */
    public function adminChangePlan(MerchantSubscription $subscription, SubscriptionPlan $plan, ?string $endDate): MerchantSubscription
    {
        return DB::transaction(function () use ($subscription, $plan, $endDate) {
            $locked = MerchantSubscription::lockForUpdate()->findOrFail($subscription->id);

            if ($locked->id !== (int) MerchantSubscription::where('merchant_id', $locked->merchant_id)->max('id')) {
                throw new ApiException('subscription.not_current', 'Only the merchant\'s current subscription can be changed', 409);
            }

            if (! $plan->is_default && $endDate === null) {
                throw new ApiException('validation.failed', 'A paid plan needs an end date', 422, ['end_date' => ['Choose when this plan ends']], 'end_date');
            }

            $locked->update([
                'subscription_plan_id' => $plan->id,
                'end_date' => $plan->is_default ? null : $endDate,
                'next_plan_id' => null,
                'is_canceled' => false,
                'canceled_at' => null,
                'cancel_reason' => null,
                'cancel_comment' => null,
            ]);

            return $locked;
        });
    }

    /**
     * Switches to the scheduled plan once the current period has ended.
     */
    public function applyScheduledFor(Merchant $merchant): void
    {
        $row = $this->currentRow($merchant);

        if (! $row || ! $row->next_plan_id || ! $row->end_date || Carbon::parse($row->end_date)->endOfDay()->isFuture()) {
            return;
        }

        DB::transaction(function () use ($row, $merchant) {
            $locked = MerchantSubscription::lockForUpdate()->find($row->id);

            if (! $locked || ! $locked->next_plan_id) {
                return;
            }

            $next = SubscriptionPlan::find($locked->next_plan_id);

            if ($next) {
                MerchantSubscription::create([
                    'merchant_id' => $merchant->id,
                    'subscription_plan_id' => $next->id,
                    'start_date' => now(),
                    'end_date' => $next->is_default ? null : now()->addMonth(),
                    'transaction_status' => 'Paid',
                ]);
            }

            $locked->update(['next_plan_id' => null]);
        });
    }

    /**
     * Used by the daily command so downgrades apply even if the merchant never
     * opens the app.
     */
    public function applyAllScheduled(): int
    {
        $merchantIds = MerchantSubscription::whereNotNull('next_plan_id')
            ->whereDate('end_date', '<', now()->toDateString())
            ->distinct()
            ->pluck('merchant_id');

        Merchant::whereIn('id', $merchantIds)->get()->each(fn (Merchant $merchant) => $this->applyScheduledFor($merchant));

        return $merchantIds->count();
    }

    private function scheduleDowngrade(Merchant $merchant, SubscriptionState $state, SubscriptionPlan $target): array
    {
        $row = $state->row;

        if ($row->is_canceled) {
            throw new ApiException('subscription.change_pending', 'Your plan is already set to end. Nothing to change.', 409);
        }

        if ((int) $row->next_plan_id !== $target->id) {
            $row->update(['next_plan_id' => $target->id]);
            $this->applyScheduledFor($merchant);
        }

        return [
            'status' => 200,
            'message' => 'You will move to '.self::planName($target).' on '.$state->expiresAt->format('j F'),
            'data' => [
                'status' => 'scheduled',
                'current_plan' => $state->plan->key,
                'next_plan' => $target->key,
                'effective_at' => ApiResponse::iso($state->expiresAt),
            ],
        ];
    }

    private function startPayment(Merchant $merchant, SubscriptionPlan $target, bool $isRenewal, ?string $rail, ?string $walletNumber, string $replayKey): array
    {
        if ($rail === null) {
            throw new ApiException('validation.failed', 'Please check the form', 422, ['rail' => ['Choose how you will pay']], 'rail');
        }

        if ($rail === 'card') {
            throw new ApiException('payment.rail_unavailable', 'Card payments are not available yet', 422, [], 'rail');
        }

        $wallet = null;

        if ($rail !== 'cash') {
            if (! $walletNumber) {
                throw new ApiException('validation.failed', 'Please check the form', 422, ['wallet_number' => ['Enter the wallet number to bill']], 'wallet_number');
            }

            $wallet = PhoneNumber::normalize($walletNumber);

            if (PhoneNumber::carrier($wallet) !== $rail) {
                throw new ApiException('payment.wallet_invalid', 'That wallet number does not belong to '.ucfirst($rail), 422, [], 'wallet_number');
            }
        }

        return Cache::lock('subscription-change:'.$merchant->id, 60)->block(10, function () use ($merchant, $target, $isRenewal, $rail, $wallet, $replayKey) {
            $this->assertNoPendingChange($merchant);

            $amount = (int) $target->price_slsh;
            $publicId = Invoice::generatePublicId();

            $attributes = [
                'public_id' => $publicId,
                'merchant_id' => $merchant->id,
                'subscription_plan_id' => $target->id,
                'first_name' => $merchant->first_name,
                'last_name' => $merchant->last_name,
                'mobile_number' => PhoneNumber::normalize($merchant->phone_number),
                'amount' => $amount,
                'currency' => config('exelo.alt_currency'),
                'status' => 'Pending',
                'type' => 'Subscription',
                'rail' => $rail,
            ];

            if ($rail === 'cash') {
                $attributes += [
                    'invoice_id' => 'CASH-'.$publicId,
                    'transaction_id' => 'cash_'.$publicId,
                    'hash' => '0',
                    'payment_method' => 'cash',
                    'expires_at' => now()->addHours(config('exelo.subscription.cash_ttl_hours')),
                ];
            } else {
                $issued = $this->gateway->issue($rail, $wallet, $amount, $attributes['currency']);

                $attributes += [
                    'invoice_id' => $issued['invoice_id'],
                    'transaction_id' => $issued['transaction_id'],
                    'hash' => $issued['hash'],
                    'wallet_number' => $wallet,
                    'expires_at' => now()->addSeconds(config('exelo.registration.invoice_ttl_seconds')),
                ];
            }

            $invoice = Invoice::create($attributes);

            Cache::put($replayKey, $invoice->id, now()->addDay());

            return $this->paymentPayload($invoice, $target, $isRenewal);
        });
    }

    private function assertNoPendingChange(Merchant $merchant): void
    {
        $pending = Invoice::where('type', 'Subscription')
            ->where('merchant_id', $merchant->id)
            ->where('status', 'Pending')
            ->whereNull('consumed_at')
            ->get();

        foreach ($pending as $invoice) {
            if ($invoice->expires_at?->isPast()) {
                $invoice->update(['status' => 'Expired', 'error_reason' => 'Payment was not completed in time']);

                continue;
            }

            throw new ApiException('subscription.change_pending', 'An earlier plan change is still waiting for payment', 409, ['charge_id' => $invoice->public_id]);
        }
    }

    /**
     * A retry with the same idempotency key reports where that payment stands now,
     * instead of repeating "approve the payment" for one that is already settled.
     */
    private function replayPayload(Invoice $invoice, SubscriptionPlan $target): array
    {
        if ($invoice->status === 'Pending' && $invoice->expires_at?->isPast()) {
            $invoice->update(['status' => 'Expired', 'error_reason' => 'Payment was not completed in time']);
        }

        if ($invoice->status === 'Pending') {
            return $this->paymentPayload($invoice, $target);
        }

        if ($invoice->status === 'Paid') {
            return [
                'status' => 200,
                'message' => 'This payment was already received. '.self::planName($target).' is active.',
                'data' => [
                    'status' => 'paid',
                    'charge_id' => $invoice->public_id,
                    'rail' => $invoice->rail,
                    'amount' => Money::format((int) $invoice->amount, $invoice->currency),
                    'paid_at' => ApiResponse::iso($invoice->paid_at),
                    'applies_on_payment' => false,
                ],
            ];
        }

        throw new ApiException('subscription.charge_closed', 'That payment is no longer open. Start again.', 409, [
            'charge_id' => $invoice->public_id,
            'status' => strtolower($invoice->status),
        ]);
    }

    private function paymentPayload(Invoice $invoice, SubscriptionPlan $target, bool $isRenewal = false): array
    {
        $name = self::planName($target);
        $amount = Money::format((int) $invoice->amount, $invoice->currency);

        if ($invoice->rail === 'cash') {
            $message = "Pay {$amount['display']} in cash to an EXELO agent. {$name} starts once they confirm.";
        } else {
            $message = 'Approve the payment on your phone to '.($isRenewal ? 'renew' : 'start')." {$name}";
        }

        return [
            'status' => 202,
            'message' => $message,
            'data' => [
                'status' => 'payment_required',
                'charge_id' => $invoice->public_id,
                'rail' => $invoice->rail,
                'amount' => $amount,
                'next_action' => $invoice->rail === 'cash' ? 'await_cash_confirmation' : 'await_customer_approval',
                'poll' => '/api/v1/payments/charges/'.$invoice->public_id,
                'poll_after' => config('exelo.registration.poll_after_seconds'),
                'expires_at' => ApiResponse::iso($invoice->expires_at),
                'applies_on_payment' => true,
            ],
        ];
    }

    private function currentRow(Merchant $merchant): ?MerchantSubscription
    {
        return MerchantSubscription::where('merchant_id', $merchant->id)
            ->with(['subscriptionPlan', 'nextPlan'])
            ->latest()
            ->latest('id')
            ->first();
    }

    private function idempotencyCacheKey(Merchant $merchant, string $idempotencyKey): string
    {
        return 'subscription-change:'.sha1($merchant->id.'|'.$idempotencyKey);
    }

    private function stateMessage(SubscriptionPlan $plan, SubscriptionPlan $effective, string $status, ?Carbon $end, ?array $grace): string
    {
        $name = self::planName($plan);

        return match (true) {
            $status === 'cancelled' => "Cancelled. You keep {$name} until {$end->format('j F')}.",
            $status === 'grace' => "Your {$name} package has expired. Renew by {$grace['ends_at']->format('j F')} to keep it.",
            $status === 'expired' => "Your {$name} package has expired. You are on the ".self::planName($effective).' package.',
            default => "You are currently on the {$name} package.",
        };
    }
}
