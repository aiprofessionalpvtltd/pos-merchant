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
    ) {}

    public function methods(Merchant $merchant): array
    {
        $wallets = collect($this->profiles->wallets($merchant)['wallets'])
            ->map(fn (array $wallet) => collect($wallet)->only(['rail', 'number', 'status', 'label'])->all())
            ->all();

        $card = config('exelo.payments.card');

        return [
            'wallets' => $wallets,
            'accepts' => $this->acceptedRails($merchant),
            'card' => ['enabled' => $card['enabled'], 'provider' => 'braintree', 'environment' => $card['environment']],
        ];
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

        $fee = $this->fee($merchant, $rail, $amount);
        $customerCharge = $amount + ($fee['payer'] === 'customer' ? $fee['amount'] : 0);
        $merchantReceives = $amount - ($fee['payer'] === 'merchant' ? $fee['amount'] : 0);

        $quote = [
            'quote_id' => 'qte_'.Str::upper(Str::ulid()->toBase32()),
            'amount' => Money::of($amount, $currency),
            'customer_charge' => Money::of($customerCharge, $currency),
            'merchant_receives' => Money::of($merchantReceives, $currency),
            'fees' => [
                'platform' => Money::of($fee['amount'], $currency),
                'rail' => Money::of(0, $currency),
            ],
            'fee_payer' => $fee['amount'] > 0 ? $fee['payer'] : null,
            'amount_alt' => $this->alternate($amount, $currency, $rate),
            'exchange_rate' => $rate,
            'expires_at' => ApiResponse::iso(now()->addSeconds(config('exelo.payments.quote_ttl_seconds'))),
        ];

        Cache::put(
            'payment-quote:'.$quote['quote_id'],
            ['merchant_id' => $merchant->id, 'rail' => $rail, 'purpose' => $input['purpose'], 'quote' => $quote],
            config('exelo.payments.quote_ttl_seconds'),
        );

        return $quote;
    }

    /**
     * Cash carries no fee. Wallet rails carry one combined rate, paid by the customer on Gold and by the shop otherwise.
     *
     * @return array{amount: int, payer: 'customer'|'merchant'}
     */
    public function fee(Merchant $merchant, string $rail, int $amount): array
    {
        $payer = $this->subscriptions->state($merchant)->effectivePlan->key === 'gold' ? 'customer' : 'merchant';

        if ($rail === 'cash') {
            return ['amount' => 0, 'payer' => $payer];
        }

        return ['amount' => (int) round($amount * config('exelo.payments.wallet_fee_rate')), 'payer' => $payer];
    }

    private function alternate(int $amount, string $currency, int $rate): array
    {
        if ($currency === 'USD') {
            return Money::of((int) round($amount * $rate / 100), config('exelo.alt_currency'));
        }

        return Money::usd((int) round($amount / $rate * 100));
    }
}
