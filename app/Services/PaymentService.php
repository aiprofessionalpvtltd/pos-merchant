<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Merchant;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PaymentService
{
    private const WALLET_RAILS = ['zaad', 'edahab'];

    public function __construct(
        private readonly MerchantProfileService $profiles,
        private readonly SubscriptionService $subscriptions,
        private readonly PaymentSettingsService $paymentSettings,
    ) {}

    public function methods(Merchant $merchant): array
    {
        $wallets = collect($this->profiles->wallets($merchant)['wallets'])
            ->map(fn (array $wallet) => collect($wallet)->only(['rail', 'number', 'status', 'label'])->all())
            ->all();

        $card = config('exelo.payments.card');

        return [
            'shop' => $this->shopSummary($merchant),
            'wallets' => $wallets,
            'accepts' => $this->acceptedRails($merchant),
            'card' => ['enabled' => $card['enabled'], 'provider' => 'braintree', 'environment' => $card['environment']],
        ];
    }

    /**
     * Which shop a payment endpoint is acting on: always the token's current shop,
     * never one the caller picks. Included so an app juggling several shops can
     * tell them apart without a second call.
     *
     * @return array{id: int, business_name: ?string}
     */
    public function shopSummary(Merchant $merchant): array
    {
        return ['id' => $merchant->id, 'business_name' => $merchant->business_name];
    }

    /**
     * @return array<int, string>
     */
    public function acceptedRails(Merchant $merchant): array
    {
        $verified = collect($this->profiles->wallets($merchant)['wallets'])
            ->where('status', 'verified')
            ->pluck('rail');

        $rails = collect(self::WALLET_RAILS)->filter(fn (string $rail) => $verified->contains($rail))->values()->all();
        $rails[] = 'cash';

        if (config('exelo.payments.card.enabled')) {
            $rails[] = 'card';
        }

        if (config('exelo.payments.nfc.enabled')) {
            $rails[] = 'nfc';
        }

        return $rails;
    }

    /**
     * @param  array{amount: array{amount: int, currency: string}, rail: string, purpose: string}  $input
     */
    public function quote(Merchant $merchant, array $input): array
    {
        $accepts = $this->acceptedRails($merchant);
        $rail = $input['rail'];

        if (! in_array($rail, $accepts, true)) {
            throw new ApiException('payment.rail_unavailable', 'That payment method is not available for this shop', 422, ['available_rails' => $accepts], 'rail');
        }

        $currency = strtoupper($input['amount']['currency']);
        $amount = (int) $input['amount']['amount'];
        $rate = $merchant->effectiveExchangeRate();
        // Charges are always the sale total in USD cents. An SLSH figure is snapped to
        // the nearest cent first, then converted back, so the quote shows the shillings
        // eDahab will actually be asked for (564 SLSH at 10,500 is 5 cents, billed as 525).
        $saleCents = $currency === 'USD' ? $amount : (int) round($amount / $rate * 100);

        if ($saleCents < 1) {
            throw new ApiException('validation.failed', 'Please check the form', 422, ['amount.amount' => ['That amount is less than one cent']], 'amount.amount');
        }

        $fee = $this->fee($merchant, $rail, $saleCents);
        $customerCents = $saleCents + ($fee['payer'] === 'customer' ? $fee['amount'] : 0);
        $merchantCents = $saleCents - ($fee['payer'] === 'merchant' ? $fee['amount'] : 0);
        $inAskedCurrency = fn (int $cents): array => $currency === 'USD'
            ? Money::usd($cents)
            : Money::of((int) round($cents * $rate / 100), config('exelo.alt_currency'));

        $quote = [
            'quote_id' => 'qte_'.Str::upper(Str::ulid()->toBase32()),
            'shop' => $this->shopSummary($merchant),
            'amount' => Money::of($amount, $currency),
            'customer_charge' => $inAskedCurrency($customerCents),
            'merchant_receives' => $inAskedCurrency($merchantCents),
            'fees' => [
                'platform' => $inAskedCurrency($fee['amount']),
                'rail' => $inAskedCurrency(0),
            ],
            'fee_payer' => $fee['amount'] > 0 ? $fee['payer'] : null,
            'amount_alt' => $this->alternate($amount, $currency, $rate),
            'charge_amount' => Money::usd($saleCents),
            'exchange_rate' => $rate,
            'expires_at' => ApiResponse::iso(now()->addSeconds(config('exelo.payments.quote_ttl_seconds'))),
        ];

        Cache::put(
            'payment-quote:'.$quote['quote_id'],
            [
                'merchant_id' => $merchant->id,
                'rail' => $rail,
                'purpose' => $input['purpose'],
                'sale_cents' => $saleCents,
                'fee_cents' => $fee['amount'],
                'fee_payer' => $fee['payer'],
                'quote' => $quote,
            ],
            config('exelo.payments.quote_ttl_seconds'),
        );

        return $quote;
    }

    /**
     * Cash carries no fee. Wallet rails carry the EXELO sales fee percent from payment settings,
     * paid by the customer on Gold and by the shop otherwise.
     *
     * @return array{amount: int, payer: 'customer'|'merchant'}
     */
    public function fee(Merchant $merchant, string $rail, int $amount): array
    {
        $payer = $this->subscriptions->state($merchant)->effectivePlan->key === 'gold' ? 'customer' : 'merchant';

        if ($rail === 'cash') {
            return ['amount' => 0, 'payer' => $payer];
        }

        return ['amount' => (int) round($amount * $this->paymentSettings->salesFeeRate()), 'payer' => $payer];
    }

    private function alternate(int $amount, string $currency, int $rate): array
    {
        if ($currency === 'USD') {
            return Money::of((int) round($amount * $rate / 100), config('exelo.alt_currency'));
        }

        return Money::usd((int) round($amount / $rate * 100));
    }
}
