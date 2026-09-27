<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\MerchantSubscription;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Carbon;

/**
 * Invoice numbers, the admin list row and the data behind the printable / PDF
 * invoice. Only a paid invoice has a document.
 */
class InvoiceDocumentService
{
    private ?Setting $setting = null;

    private bool $settingLoaded = false;

    private const METHODS = [
        'cash' => 'Cash',
        'edahab' => 'eDahab',
        'zaad' => 'Zaad',
        'card' => 'Card',
        'number' => 'Mobile wallet',
    ];

    public function number(Invoice $invoice): string
    {
        $prefix = strtoupper(trim((string) ($this->setting()?->invoice_prefix ?? '')));

        return ($prefix !== '' ? $prefix : 'INV').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);
    }

    public function isAvailable(Invoice $invoice): bool
    {
        return $invoice->status === 'Paid';
    }

    public function filename(Invoice $invoice): string
    {
        return $this->number($invoice).'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    public function listRow(Invoice $invoice): array
    {
        return [
            'number' => $this->number($invoice),
            'merchant' => $this->partyName($invoice),
            'amount' => $this->formatAmount((float) $invoice->amount, $invoice->currency, $invoice),
            'method' => $this->method($invoice),
            'has_document' => $this->isAvailable($invoice),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(Invoice $invoice): array
    {
        $company = $this->setting();
        $merchant = $invoice->merchant->exists ? $invoice->merchant : null;

        return [
            'number' => $this->number($invoice),
            'status' => 'PAID',
            'issued_at' => ($invoice->paid_at ?? $invoice->updated_at)?->format('d M Y'),
            'generated_at' => now()->format('d M Y H:i'),
            'company' => [
                'name' => $company?->company_name ?: config('app.name'),
                'email' => $company?->company_email,
                'website' => $company?->company_website,
                'phone' => $company?->phone_no,
                'address' => $company?->address_one,
                'logo_path' => $this->logoPath($company?->logo),
            ],
            'billed_to' => [
                'name' => $this->partyName($invoice),
                'business' => $merchant?->business_name,
                'phone' => $merchant?->phone_number ?? $invoice->mobile_number,
                'email' => $merchant?->email,
                'address' => collect([$merchant?->city, $merchant?->state])->filter()->implode(', ') ?: $merchant?->location,
            ],
            'payment' => [
                'method' => $this->method($invoice),
                'reference' => $invoice->e_transaction_id ?: $invoice->transaction_id,
                'invoice_ref' => $invoice->public_id ?: $invoice->invoice_id,
                'paid_at' => $invoice->paid_at?->format('d M Y H:i'),
            ],
            'items' => [$this->lineItem($invoice, $company?->company_name ?: config('app.name'))],
            'currency' => $invoice->currency,
            'total' => $this->formatAmount((float) $invoice->amount, $invoice->currency, $invoice),
        ];
    }

    private function lineItem(Invoice $invoice, string $company): array
    {
        $amount = $this->formatAmount((float) $invoice->amount, $invoice->currency, $invoice);

        if ($invoice->type === 'Subscription') {
            $plan = $invoice->subscription_plan_id ? SubscriptionPlan::withTrashed()->find($invoice->subscription_plan_id) : null;
            $period = MerchantSubscription::where('invoice_id', $invoice->id)->first();

            return [
                'description' => ($plan ? SubscriptionService::planName($plan) : 'Plan').' subscription (monthly)',
                'detail' => $period ? $this->period($period) : null,
                'amount' => $amount,
            ];
        }

        $descriptions = [
            'Registration' => "{$company} merchant registration fee",
            'Verification' => 'Payout wallet verification fee',
            'POS' => 'POS payment',
            'Pin' => 'PIN reset fee',
        ];

        return [
            'description' => $descriptions[$invoice->type] ?? ($invoice->type ? "{$invoice->type} payment" : 'Payment'),
            'detail' => null,
            'amount' => $amount,
        ];
    }

    private function period(MerchantSubscription $subscription): string
    {
        $start = Carbon::parse($subscription->start_date)->format('d M Y');

        return $subscription->end_date
            ? $start.' – '.Carbon::parse($subscription->end_date)->format('d M Y')
            : "From {$start}";
    }

    private function partyName(Invoice $invoice): string
    {
        $merchant = $invoice->merchant->exists ? $invoice->merchant : null;

        $name = $merchant?->business_name
            ?: trim(($merchant?->first_name ?? $invoice->first_name).' '.($merchant?->last_name ?? $invoice->last_name));

        return $name !== '' ? $name : ($invoice->mobile_number ?: '—');
    }

    private function method(Invoice $invoice): string
    {
        $key = strtolower((string) ($invoice->rail ?: $invoice->payment_method));

        return self::METHODS[$key] ?? ($key !== '' ? ucfirst($key) : '—');
    }

    /**
     * Shows both currencies: the amount as stored, and its equivalent at the
     * rate the sale actually settled at (the order's own frozen `exchange_rate`
     * when there is one, else the shop's current rate).
     */
    private function formatAmount(float $amount, ?string $currency, Invoice $invoice): string
    {
        $currency = strtoupper((string) $currency);
        $rate = $this->rate($invoice);

        if ($currency === 'SLSH') {
            $usd = $rate > 0 ? $amount / $rate : 0.0;

            return number_format($amount).' SLSH ($'.number_format($usd, 2).')';
        }

        $slsh = $rate > 0 ? round($amount * $rate) : 0;

        return '$'.number_format($amount, 2).' ('.number_format($slsh).' SLSH)';
    }

    private function rate(Invoice $invoice): int
    {
        return (int) ($invoice->order->exchange_rate ?: $invoice->merchant?->effectiveExchangeRate() ?: config('exelo.conversion_rate'));
    }

    private function setting(): ?Setting
    {
        if (! $this->settingLoaded) {
            $this->setting = Setting::getSetting();
            $this->settingLoaded = true;
        }

        return $this->setting;
    }

    /**
     * A logo is shown only when its file can really be found; the uploader is not
     * wired up yet, so most installs have none.
     */
    private function logoPath(?string $logo): ?string
    {
        if (! $logo) {
            return null;
        }

        foreach ([public_path($logo), public_path('storage/'.$logo), storage_path('app/public/'.$logo)] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
