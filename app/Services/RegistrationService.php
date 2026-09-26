<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantAccount;
use App\Models\MerchantSubscription;
use App\Models\Shop;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class RegistrationService
{
    private const TYPES = ['registration' => 'Registration', 'verification' => 'Verification'];

    public function __construct(
        private readonly WalletGateway $gateway,
        private readonly InvoicePaymentService $payments,
        private readonly PaymentSettingsService $settings,
        private readonly MerchantProfileService $profiles,
    ) {}

    public function states(): array
    {
        return ['country' => config('exelo.country'), 'states' => config('exelo.states')];
    }

    public function quote(string $purpose): array
    {
        $fees = $this->settings->fees($purpose);
        $currency = config('exelo.alt_currency');
        $expiresAt = now()->addSeconds(config('exelo.registration.quote_ttl_seconds'));
        $quoteId = 'qte_'.Str::upper(Str::random(10));

        $charge = $fees['base'] + $fees['fee'];

        Cache::put('registration-quote:'.$quoteId, [
            'purpose' => $purpose,
            'amount' => $charge,
            'base' => $fees['base'],
            'fee' => $fees['fee'],
            'currency' => $currency,
        ], $expiresAt);

        return [
            'purpose' => $purpose,
            'base' => $this->inBoth($fees['base'], $currency),
            'exelo_fee' => $this->inBoth($fees['fee'], $currency),
            'total' => $this->inBoth($charge, $currency),
            'quote_id' => $quoteId,
            'expires_at' => ApiResponse::iso($expiresAt),
        ];
    }

    public function checkPhone(string $phoneNumber): array
    {
        if ($taken = $this->completedMerchant($phoneNumber)) {
            return [
                'message' => $this->phoneTaken($taken)->getMessage(),
                'data' => ['available' => false, 'registration_complete' => true, 'invoice_required' => false, 'pending_invoice' => null],
            ];
        }

        $paid = Invoice::paid()
            ->where('type', self::TYPES['registration'])
            ->whereNull('consumed_at')
            ->whereIn('mobile_number', PhoneNumber::variants($phoneNumber))
            ->latest('id')
            ->first();

        if ($paid) {
            return [
                'message' => 'You already paid. Continue where you left off.',
                'data' => [
                    'available' => true,
                    'registration_complete' => false,
                    'invoice_required' => false,
                    'pending_invoice' => [
                        'invoice_id' => $this->publicId($paid),
                        'status' => 'paid',
                        'paid_at' => ApiResponse::iso($paid->paid_at ?? $paid->updated_at),
                    ],
                ],
            ];
        }

        return [
            'message' => 'Number available',
            'data' => ['available' => true, 'registration_complete' => false, 'invoice_required' => true, 'pending_invoice' => null],
        ];
    }

    public function issueInvoice(array $input): array
    {
        $phone = PhoneNumber::normalize($input['phone_number']);
        $wallet = PhoneNumber::normalize($input['wallet_number']);
        $purpose = $input['purpose'];
        $rail = $input['rail'];

        if ($purpose === 'registration' && ($taken = $this->completedMerchant($phone))) {
            throw $this->phoneTaken($taken);
        }

        if (PhoneNumber::carrier($wallet) !== $rail) {
            throw new ApiException('payment.wallet_invalid', 'That wallet number does not belong to '.ucfirst($rail), 422, [], 'wallet_number');
        }

        if ($purpose === 'verification') {
            $this->assertVerifiable($phone, $wallet, $rail);
        }

        $quote = Cache::get('registration-quote:'.$input['quote_id']);

        if (! $quote || $quote['purpose'] !== $purpose) {
            throw new ApiException('quote.expired', 'The price has expired. Get a new quote.', 410);
        }

        // Scoped by purpose: the same key on a registration and a verification must not collide.
        $idempotencyKey = 'registration-invoice:'.sha1($phone.'|'.$purpose.'|'.$input['idempotency_key']);

        return Cache::lock($idempotencyKey.':lock', 60)->block(10, function () use ($idempotencyKey, $phone, $wallet, $purpose, $rail, $quote) {
            $existingId = Cache::get($idempotencyKey);

            if ($existingId && ($existing = Invoice::find($existingId))) {
                return $this->pendingPayload($existing);
            }

            $issued = $this->gateway->issue($rail, $wallet, $quote['amount'], $quote['currency'], 'EXELO '.$purpose);

            $invoice = Invoice::create([
                // The split of the amount, as quoted: what the service costs and EXELO's fee on top.
                'meta' => array_filter(['base' => $quote['base'] ?? null, 'exelo_fee' => $quote['fee'] ?? null], fn ($value) => $value !== null)
                    + Invoice::issuedMeta($issued) ?: null,
                'public_id' => $this->newPublicId(),
                'invoice_id' => $issued['invoice_id'],
                'transaction_id' => $issued['transaction_id'],
                'hash' => $issued['hash'],
                'mobile_number' => $phone,
                'wallet_number' => $wallet,
                'rail' => $rail,
                'amount' => $quote['amount'],
                'currency' => $quote['currency'],
                'status' => 'Pending',
                'type' => self::TYPES[$purpose],
                'expires_at' => now()->addSeconds(config('exelo.registration.invoice_ttl_seconds')),
            ]);

            Cache::put($idempotencyKey, $invoice->id, now()->addDay());

            return $this->pendingPayload($invoice, 'Approve the payment on your phone');
        });
    }

    public function invoiceStatus(string $publicId): array
    {
        $invoice = Invoice::where('public_id', $publicId)->whereIn('type', array_values(self::TYPES))->first();

        if (! $invoice) {
            throw new ApiException('invoice.not_found', 'We could not find that payment', 404);
        }

        if ($invoice->status === 'Pending') {
            $this->payments->refresh($invoice);
        }

        return match ($invoice->status) {
            'Pending' => ['message' => null, 'data' => [
                'invoice_id' => $invoice->public_id,
                'status' => 'pending',
                'poll_after' => config('exelo.registration.poll_after_seconds'),
                'expires_at' => ApiResponse::iso($invoice->expires_at),
            ] + ($invoice->isPromptDeclined() ? ['prompt' => 'declined'] : [])],
            'Paid' => ['message' => 'Payment received', 'data' => [
                'invoice_id' => $invoice->public_id,
                'status' => 'paid',
                'paid_at' => ApiResponse::iso($invoice->paid_at),
                'amount' => $this->money((int) $invoice->amount, $invoice->currency),
                'receipt_no' => 'EXL-RCP-'.str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT),
            ]],
            default => ['message' => null, 'data' => [
                'invoice_id' => $invoice->public_id,
                'status' => strtolower($invoice->status),
                'error_reason' => $invoice->error_reason,
            ]],
        };
    }

    public function registerMerchant(array $input): array
    {
        $phone = PhoneNumber::normalize($input['phone_number']);
        $state = collect(config('exelo.states'))->firstWhere('code', $input['state'] ?? null);
        $email = $input['email'] ?? substr($phone, 1).'@email.com';

        return DB::transaction(function () use ($input, $phone, $state, $email) {
            $invoice = $this->lockedRegistrationInvoice($input['invoice_id'], $phone);

            if ($taken = $this->completedMerchant($phone)) {
                throw $this->phoneTaken($taken);
            }

            $this->assertCodesFree($input, Merchant::whereIn('phone_number', PhoneNumber::variants($phone))->value('id'));

            if (User::withTrashed()->where('email', $email)->exists()) {
                throw new ApiException('validation.failed', 'Please check the form', 422, ['email' => ['This email is already in use']], 'email');
            }

            // The sign-in (users) and the merchant (merchants) it belongs to.
            $user = User::create([
                'name' => $input['first_name'].' '.$input['last_name'],
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'user_type' => 'merchant',
            ]);

            $account = MerchantAccount::create([
                'user_id' => $user->id,
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'dob' => $input['dob'],
                'email' => $input['email'] ?? null,
                'phone_number' => $phone,
            ]);

            $role = Role::where('name', 'Merchant')->first();

            if ($role) {
                $user->assignRole($role);
            }

            // The merchant only: its first shop, with its own number, comes after the phone is verified.
            if (empty($input['business_name'])) {
                $invoice->forceFill(['consumed_at' => now(), 'user_id' => $user->id])->save();

                return [
                    'merchant' => null,
                    'user' => ['id' => $user->id, 'type' => 'merchant', 'has_pin' => false, 'phone_number' => $phone],
                    'merchant_account' => ['id' => $account->id, 'phone_number' => $phone, 'phone_verified' => false],
                    'subscription' => null,
                    'next_step' => 'set_pin',
                ];
            }

            // Deprecated: account and first shop in one call, sharing one number.
            ['merchant' => $merchant, 'plan' => $plan] = $this->createShop($user, $invoice, $input, $phone, $state, $input);
            $planId = $plan->id;

            return [
                'merchant' => [
                    'id' => $merchant->id,
                    'business_name' => $merchant->business_name,
                    'merchant_code' => $merchant->merchant_code,
                    'state' => $merchant->state,
                    'state_code' => $merchant->state_code,
                    'city' => $merchant->city,
                    'location' => $merchant->location,
                    'created_at' => ApiResponse::iso($merchant->created_at),
                ],
                'user' => ['id' => $user->id, 'type' => 'merchant', 'has_pin' => false],
                'subscription' => ['plan_id' => $planId, 'plan' => $plan->key, 'status' => 'active'],
                'next_step' => 'set_pin',
            ];
        });
    }

    /**
     * A signed-in merchant creates a shop. Free: the only charges are the account's
     * registration fee and its phone verification fee. The shop has its own new number;
     * the merchant's verified number becomes its payout wallet (docs/merchant-onboarding.md).
     *
     * @param  array{business_name: string, phone_number: string, state: string, city: string, email?: ?string, merchant_code?: ?string, other_merchant_code?: ?string}  $input
     * @param  array{first_name: ?string, last_name: ?string, dob: ?string}  $person  The owner's details, copied onto the shop
     * @return array{merchant: Merchant, plan: SubscriptionPlan}
     */
    public function openShop(User $owner, array $input, array $person): array
    {
        $phone = PhoneNumber::normalize($input['phone_number']);
        $state = collect(config('exelo.states'))->firstWhere('code', $input['state']);

        if (! $owner->merchantAccount?->isPhoneVerified() && $owner->accessibleShops()->isEmpty()) {
            throw new ApiException('account.phone_unverified', 'Verify your phone number before creating a shop', 409);
        }

        return DB::transaction(function () use ($owner, $input, $person, $phone, $state) {
            // A closed shop keeps its number, and a merchant's own number is theirs alone.
            $isTaken = Merchant::withTrashed()
                ->whereIn('phone_number', PhoneNumber::variants($phone))
                ->where(fn ($query) => $query->whereNotNull('user_id')->orWhereNotNull('deleted_at'))
                ->exists()
                || MerchantAccount::withTrashed()->whereIn('phone_number', PhoneNumber::variants($phone))->exists();

            if ($isTaken) {
                throw new ApiException('registration.phone_taken', 'This number is already used on EXELO. A shop needs its own number.', 409, [], 'phone_number');
            }

            $this->assertCodesFree($input, Merchant::whereIn('phone_number', PhoneNumber::variants($phone))->value('id'));

            $created = $this->createShop($owner, null, $input, $phone, $state, $person);

            $this->giveVerifiedWallet($owner, $created['merchant']);

            return $created;
        });
    }

    /**
     * Completes the merchant account's phone verification with a paid verification invoice
     * that was paid from that phone. Afterwards the merchant can create shops.
     *
     * @return array{phone_number: string, phone_verified: bool, next_step: ?string}
     */
    public function completeAccountVerification(User $user, string $publicInvoiceId): array
    {
        $account = $user->merchantAccount;

        if ($user->isEmployee() || ! $account) {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can do this', 403);
        }

        DB::transaction(function () use ($user, $account, $publicInvoiceId) {
            $invoice = Invoice::where('public_id', $publicInvoiceId)
                ->where('type', self::TYPES['verification'])
                ->whereIn('mobile_number', PhoneNumber::variants((string) $account->phone_number))
                ->lockForUpdate()
                ->first();

            if (! $account->phone_number || ! $invoice) {
                throw new ApiException('validation.failed', 'Please check the form', 422, ['invoice_id' => ['Unknown payment']], 'invoice_id');
            }

            if ($invoice->consumed_at) {
                throw new ApiException('registration.invoice_consumed', 'That payment was already used', 409);
            }

            if ($invoice->status !== 'Paid') {
                throw new ApiException('registration.invoice_unpaid', 'The verification fee has not been paid', 402);
            }

            if (PhoneNumber::normalize($invoice->wallet_number) !== PhoneNumber::normalize($account->phone_number)) {
                throw new ApiException('validation.failed', 'Please check the form', 422, ['invoice_id' => ['That payment was not made from your number']], 'invoice_id');
            }

            $invoice->forceFill(['consumed_at' => now(), 'user_id' => $user->id])->save();
            $account->forceFill(['phone_verified_at' => now()])->save();
        });

        return [
            'phone_number' => $account->phone_number,
            'phone_verified' => true,
            'next_step' => $user->onboardingNextStep(),
        ];
    }

    /**
     * The merchant's verified number becomes the new shop's verified payout wallet, so the shop
     * takes wallet payments without another fee. Skipped when the number can't be used there.
     */
    private function giveVerifiedWallet(User $owner, Merchant $shop): void
    {
        $account = $owner->merchantAccount;
        $number = $account?->phone_number ? PhoneNumber::normalize($account->phone_number) : null;
        $rail = $number ? PhoneNumber::carrier($number) : null;

        if (! $account?->isPhoneVerified() || ! in_array($rail, ['zaad', 'edahab'], true)) {
            return;
        }

        try {
            $this->profiles->verifyWalletFromPayment($shop, $rail, $number);
        } catch (ApiException) {
            // Another owner's shop already has this number as its payout wallet.
        }
    }

    /**
     * The paid, unused registration invoice for this phone, locked for the rest of the transaction.
     */
    private function lockedRegistrationInvoice(string $publicId, string $phone): Invoice
    {
        $invoice = Invoice::where('public_id', $publicId)
            ->where('type', self::TYPES['registration'])
            ->lockForUpdate()
            ->first();

        if (! $invoice) {
            throw new ApiException('validation.failed', 'Please check the form', 422, ['invoice_id' => ['Unknown payment']], 'invoice_id');
        }

        if ($invoice->consumed_at) {
            throw new ApiException('registration.invoice_consumed', 'That payment already created an account', 409);
        }

        if ($invoice->status !== 'Paid') {
            throw new ApiException('registration.invoice_unpaid', 'The signup fee has not been paid', 402);
        }

        if ($phone !== PhoneNumber::normalize($invoice->mobile_number)) {
            throw new ApiException('validation.failed', 'Please check the form', 422, ['phone_number' => ['This number does not match the payment']], 'phone_number');
        }

        return $invoice;
    }

    private function assertCodesFree(array $input, ?int $ignoreMerchantId): void
    {
        foreach (['merchant_code', 'other_merchant_code'] as $codeField) {
            $isTaken = ! empty($input[$codeField])
                && Merchant::where($codeField, $input[$codeField])->where('id', '!=', $ignoreMerchantId ?? 0)->exists();

            if ($isTaken) {
                throw new ApiException('validation.failed', 'Please check the form', 422, [$codeField => ['This code is already registered']], $codeField);
            }
        }
    }

    /**
     * Creates (or completes an unfinished) shop for this owner on the default plan and uses up the invoice.
     *
     * @param  array{name: string, code: string}  $state
     * @param  array{first_name: ?string, last_name: ?string, dob: ?string}  $person
     * @return array{merchant: Shop, plan: SubscriptionPlan}
     */
    private function createShop(User $owner, ?Invoice $invoice, array $input, string $phone, array $state, array $person): array
    {
        $merchant = Shop::whereIn('phone_number', PhoneNumber::variants($phone))->first() ?? new Shop;

        $merchant->fill([
            'user_id' => $owner->id,
            'merchant_id' => $owner->merchantAccount?->id,
            'first_name' => $person['first_name'],
            'last_name' => $person['last_name'],
            'dob' => $person['dob'],
            'email' => $input['email'] ?? null,
            'phone_number' => $phone,
            'business_name' => $input['business_name'],
            'state' => $state['name'],
            'state_code' => $state['code'],
            'city' => $input['city'],
            'location' => $input['city'].', '.$state['name'],
            'merchant_code' => $input['merchant_code'] ?? null,
            'other_merchant_code' => $input['other_merchant_code'] ?? null,
            'is_approved' => true,
        ])->save();

        $plan = SubscriptionPlan::default()->orderBy('id')->first() ?? SubscriptionPlan::find(config('exelo.default_subscription_plan_id'));

        // The default plan never expires, so it has no end date.
        MerchantSubscription::create([
            'merchant_id' => $merchant->id,
            'subscription_plan_id' => $plan->id,
            'start_date' => now(),
            'end_date' => $plan->is_default ? null : now()->addMonth(),
            'transaction_status' => 'Paid',
        ]);

        $invoice?->update(['consumed_at' => now(), 'merchant_id' => $merchant->id]);

        return ['merchant' => $merchant, 'plan' => $plan];
    }

    public function completeVerification(User $user, int $merchantId, ?string $publicInvoiceId): array
    {
        if ($user->isEmployee()) {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can do this', 403);
        }

        // {id} is a shop id (the route name predates shops). Any shop the merchant owns works, not only
        // the one this session is working in, so another shop's wallet can be verified without switching.
        $merchant = $user->accessibleShop($merchantId)
            ?? throw new ApiException(
                'merchant.not_found',
                'We could not find that shop among yours',
                404,
                ['your_shop_ids' => $user->accessibleShops()->pluck('id')->all()],
            );

        if ($publicInvoiceId) {
            DB::transaction(function () use ($publicInvoiceId, $merchant) {
                $invoice = Invoice::where('public_id', $publicInvoiceId)
                    ->where('type', self::TYPES['verification'])
                    ->whereIn('mobile_number', PhoneNumber::variants($merchant->phone_number))
                    ->lockForUpdate()
                    ->first();

                if (! $invoice) {
                    throw new ApiException('validation.failed', 'Please check the form', 422, ['invoice_id' => ['Unknown payment']], 'invoice_id');
                }

                if ($invoice->consumed_at) {
                    throw new ApiException('registration.invoice_consumed', 'That payment was already used', 409);
                }

                if ($invoice->status !== 'Paid') {
                    throw new ApiException('registration.invoice_unpaid', 'The verification fee has not been paid', 402);
                }

                $invoice->update(['consumed_at' => now(), 'merchant_id' => $merchant->id]);

                // The wallet that paid becomes this shop's verified payout number on its rail.
                $this->profiles->verifyWalletFromPayment($merchant, $invoice->rail, $invoice->wallet_number);
            });

            $merchant = $merchant->fresh();
        }

        $wallets = collect($this->profiles->wallets($merchant)['wallets'])
            ->mapWithKeys(fn (array $entry) => [
                $entry['rail'].'_number' => ['number' => $merchant->{$entry['rail'].'_number'}, 'status' => $entry['status']],
            ]);

        return [
            'verified' => $wallets->contains(fn (array $wallet) => $wallet['status'] === 'verified'),
            'wallets' => $wallets->all(),
        ];
    }

    /**
     * Local testing only: settles a pending invoice without a wallet prompt.
     */
    public function simulatePayment(string $publicId, string $outcome): array
    {
        if (! app()->environment('local') || ! config('exelo.simulate_payments')) {
            throw new ApiException('not_found', 'Not found', 404);
        }

        $invoice = Invoice::where('public_id', $publicId)
            ->whereIn('type', [...array_values(self::TYPES), 'Subscription'])
            ->first();

        if (! $invoice) {
            throw new ApiException('invoice.not_found', 'We could not find that payment', 404);
        }

        $this->payments->simulate($invoice, $outcome);

        if ($invoice->type === 'Subscription') {
            return ['message' => null, 'data' => $this->payments->chargePayload($invoice->refresh())];
        }

        return $this->invoiceStatus($publicId);
    }

    private function pendingPayload(Invoice $invoice, ?string $message = null): array
    {
        return [
            'message' => $message ?? 'Approve the payment on your phone',
            'data' => [
                'invoice_id' => $invoice->public_id,
                'status' => strtolower($invoice->status),
                'rail' => $invoice->rail,
                'amount' => $this->money((int) $invoice->amount, $invoice->currency),
                'next_action' => 'await_customer_approval',
                'poll_after' => config('exelo.registration.poll_after_seconds'),
                'expires_at' => ApiResponse::iso($invoice->expires_at),
            ] + ($invoice->isPromptDeclined() ? ['prompt' => 'declined'] : []),
        ];
    }

    /**
     * Whoever already uses this number: a merchant (its own number), or a shop
     * (including a closed one: a closed shop keeps its number).
     */
    private function completedMerchant(string $phoneNumber): Merchant|MerchantAccount|null
    {
        $variants = PhoneNumber::variants($phoneNumber);

        return Merchant::withTrashed()
            ->whereIn('phone_number', $variants)
            ->whereNotNull('user_id')
            ->where(fn ($query) => $query->where('is_approved', true)->orWhereNotNull('deleted_at'))
            ->first()
            ?? MerchantAccount::withTrashed()->whereIn('phone_number', $variants)->first();
    }

    private function phoneTaken(Merchant|MerchantAccount $owner): ApiException
    {
        $message = $owner instanceof Merchant && $owner->trashed()
            ? 'This number belonged to a shop that was closed. Use a different number.'
            : 'This number already has an EXELO account';

        return new ApiException('registration.phone_taken', $message, 409, [], 'phone_number');
    }

    /**
     * A verification fee is for a shop's number (the paying wallet becomes its payout number)
     * or for a merchant account's own number (which must pay from itself). Checked before charging.
     */
    private function assertVerifiable(string $phone, string $wallet, string $rail): void
    {
        $variants = PhoneNumber::variants($phone);

        if ($shop = Merchant::whereIn('phone_number', $variants)->where('is_approved', true)->first()) {
            $this->profiles->assertWalletFree($shop, $rail, $wallet);

            return;
        }

        if (MerchantAccount::whereIn('phone_number', $variants)->exists()) {
            if ($wallet !== $phone) {
                throw new ApiException('validation.failed', 'Please check the form', 422, ['wallet_number' => ['Pay from the number you are verifying']], 'wallet_number');
            }

            return;
        }

        throw new ApiException('validation.failed', 'Please check the form', 422, ['phone_number' => ['This number has no EXELO account']], 'phone_number');
    }

    private function publicId(Invoice $invoice): string
    {
        if (! $invoice->public_id) {
            $invoice->update(['public_id' => $this->newPublicId()]);
        }

        return $invoice->public_id;
    }

    private function newPublicId(): string
    {
        return 'inv_'.Str::upper(Str::ulid()->toBase32());
    }

    private function money(int $amount, string $currency): array
    {
        return ['amount' => $amount, 'currency' => $currency, 'display' => $amount.' '.$currency];
    }

    /**
     * One SLSH amount with its USD equivalent. Each is converted on its own, so the USD
     * total can differ from the sum of the USD parts by a cent.
     *
     * @return array{slsh: array, usd: array}
     */
    private function inBoth(int $slsh, string $currency): array
    {
        return ['slsh' => $this->money($slsh, $currency), 'usd' => $this->usd($slsh)];
    }

    private function usd(int $slsh): array
    {
        $cents = (int) round($slsh / config('exelo.conversion_rate') * 100);

        return ['amount' => $cents, 'currency' => 'USD', 'display' => '$'.number_format($cents / 100, 2)];
    }
}
