<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Models\Order;
use App\Models\Transaction;

/**
 * What the admin merchant pages show. `listRow` expects the merchant loaded with
 * `user` and `currentSubscription.subscriptionPlan`.
 */
class MerchantDirectoryService
{
    private const RECENT_LIMIT = 50;

    public function __construct(private readonly AccountStatusService $status) {}

    /**
     * @return array<string, mixed>
     */
    public function listRow(Merchant $merchant): array
    {
        return [
            'plan' => $this->status->plan($merchant->currentSubscription),
            'pin' => $merchant->user->exists ? $this->status->pin($merchant->user) : null,
            'address' => $this->address($merchant),
            'wallets' => $this->walletCount($merchant),
        ];
    }

    /**
     * Everything the merchant page shows. Nothing here writes.
     *
     * @return array<string, mixed>
     */
    public function detail(Merchant $merchant): array
    {
        $merchant->loadMissing('user');

        $currentSubscription = MerchantSubscription::where('merchant_id', $merchant->id)
            ->with('subscriptionPlan')
            ->latest()
            ->latest('id')
            ->first();

        $orders = Order::where('merchant_id', $merchant->id);
        $invoices = Invoice::where('merchant_id', $merchant->id);
        $transactions = Transaction::where('merchant_id', $merchant->id);

        return [
            'address' => $this->address($merchant),
            'plan' => $this->status->plan($currentSubscription),
            'pin' => $merchant->user->exists ? $this->status->pin($merchant->user) : null,
            'wallets' => [
                'Zaad' => $merchant->zaad_number,
                'eDahab' => $merchant->edahab_number,
                'Golis' => $merchant->golis_number,
                'EVC' => $merchant->evc_number,
            ],
            'subscriptions' => MerchantSubscription::where('merchant_id', $merchant->id)
                ->with(['subscriptionPlan', 'invoice'])
                ->latest()
                ->latest('id')
                ->limit(10)
                ->get(),
            'employees' => Employee::where('shop_id', $merchant->id)->orderBy('first_name')->get(),
            'pending_cash' => Invoice::where('merchant_id', $merchant->id)
                ->where('type', 'Subscription')
                ->where('rail', 'cash')
                ->where('status', 'Pending')
                ->with('merchant')
                ->latest()
                ->get(),
            'stats' => [
                'orders' => (clone $orders)->count(),
                'orders_by_status' => (clone $orders)->selectRaw('order_status, count(*) as total')->groupBy('order_status')->pluck('total', 'order_status'),
                'invoices' => (clone $invoices)->count(),
                'subscription_paid_slsh' => (int) (clone $invoices)->where('type', 'Subscription')->where('status', 'Paid')->sum('amount'),
                'transactions' => (clone $transactions)->count(),
            ],
            'recent' => [
                'limit' => self::RECENT_LIMIT,
                'orders' => $orders->latest()->limit(self::RECENT_LIMIT)->get(),
                'invoices' => $invoices->latest()->limit(self::RECENT_LIMIT)->get(),
                'transactions' => $transactions->latest()->limit(self::RECENT_LIMIT)->get(),
            ],
        ];
    }

    private function address(Merchant $merchant): ?string
    {
        // Merchants registered through v1 have city and state; older ones only a free-text location.
        $structured = collect([$merchant->city, $merchant->state])->filter()->implode(', ');

        return $structured !== '' ? $structured : $merchant->location;
    }

    private function walletCount(Merchant $merchant): int
    {
        return collect([$merchant->zaad_number, $merchant->edahab_number, $merchant->golis_number, $merchant->evc_number])
            ->filter(fn ($number) => ! empty($number) && $number !== '0')
            ->count();
    }
}
