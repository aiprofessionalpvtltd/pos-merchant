<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
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

    public function __construct(private readonly WalletGateway $gateway, private readonly InvoicePaymentService $payments) {}

    public function states(): array
    {
        return ['country' => config('exelo.country'), 'states' => config('exelo.states')];
    }

    public function quote(string $purpose): array
    {
        $fees = config('exelo.registration.fees.'.$purpose);
        $currency = config('exelo.alt_currency');
        $expiresAt = now()->addSeconds(config('exelo.registration.quote_ttl_seconds'));
        $quoteId = 'qte_'.Str::upper(Str::random(10));

        $charge = $fees['base'] + $fees['fee'];

        Cache::put('registration-quote:'.$quoteId, ['purpose' => $purpose, 'amount' => $charge, 'currency' => $currency], $expiresAt);

        return [
            'purpose' => $purpose,
            'base' => $this->money($fees['base'], $currency),
            'customer_charge' => $this->money($charge, $currency),
            'fee' => $this->money($fees['fee'], $currency),
            'amount_in_usd' => $this->usd($fees['base']),
            'quote_id' => $quoteId,
            'expires_at' => ApiResponse::iso($expiresAt),
        ];
    }

    public function checkPhone(string $phoneNumber): array
    {
        if ($this->completedMerchant($phoneNumber)) {
            return [
                'message' => 'This number already has an EXELO account',
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

        if ($purpose === 'registration' && $this->completedMerchant($phone)) {
            throw new ApiException('registration.phone_taken', 'This number already has an EXELO account', 409);
        }

        if (PhoneNumber::carrier($wallet) !== $rail) {
            throw new ApiException('payment.wallet_invalid', 'That wallet number does not belong to '.ucfirst($rail), 422, [], 'wallet_number');
        }

        $quote = Cache::get('registration-quote:'.$input['quote_id']);

        if (! $quote || $quote['purpose'] !== $purpose) {
            throw new ApiException('quote.expired', 'The price has expired. Get a new quote.', 410);
        }

        $idempotencyKey = 'registration-invoice:'.sha1($phone.'|'.$input['idempotency_key']);

        return Cache::lock($idempotencyKey.':lock', 60)->block(10, function () use ($idempotencyKey, $phone, $wallet, $purpose, $rail, $quote) {
            $existingId = Cache::get($idempotencyKey);

            if ($existingId && ($existing = Invoice::find($existingId))) {
                return $this->pendingPayload($existing);
            }

            $issued = $this->gateway->issue($rail, $wallet, $quote['amount'], $quote['currency']);

            $invoice = Invoice::create([
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
            ]],
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
        $state = collect(config('exelo.states'))->firstWhere('code', $input['state']);
        $email = $input['email'] ?? substr($phone, 1).'@email.com';

        return DB::transaction(function () use ($input, $phone, $state, $email) {
            $invoice = Invoice::where('public_id', $input['invoice_id'])
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

            if ($this->completedMerchant($phone)) {
                throw new ApiException('registration.phone_taken', 'This number already has an EXELO account', 409);
            }

            $merchant = Merchant::whereIn('phone_number', PhoneNumber::variants($phone))->first() ?? new Merchant;

            foreach (['merchant_code', 'other_merchant_code'] as $codeField) {
                $isTaken = ! empty($input[$codeField])
                    && Merchant::where($codeField, $input[$codeField])->where('id', '!=', $merchant->id ?? 0)->exists();

                if ($isTaken) {
                    throw new ApiException('validation.failed', 'Please check the form', 422, [$codeField => ['This code is already registered']], $codeField);
                }
            }

            if (User::withTrashed()->where('email', $email)->exists()) {
                throw new ApiException('validation.failed', 'Please check the form', 422, ['email' => ['This email is already in use']], 'email');
            }

            $user = User::create([
                'name' => $input['first_name'].' '.$input['last_name'],
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'user_type' => 'merchant',
            ]);

            $role = Role::where('name', 'Merchant')->first();

            if ($role) {
                $user->assignRole($role);
            }

            $merchant = Merchant::whereIn('phone_number', PhoneNumber::variants($phone))->first() ?? new Merchant;

            $merchant->fill([
                'user_id' => $user->id,
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'dob' => $input['dob'],
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
            $planId = $plan->id;

            // The default plan never expires, so it has no end date.
            MerchantSubscription::create([
                'merchant_id' => $merchant->id,
                'subscription_plan_id' => $planId,
                'start_date' => now(),
                'end_date' => $plan->is_default ? null : now()->addMonth(),
                'transaction_status' => 'Paid',
            ]);

            $invoice->update(['consumed_at' => now(), 'merchant_id' => $merchant->id]);

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
                'subscription' => ['plan_id' => $planId, 'plan' => $planId === 1 ? 'gold' : 'silver', 'status' => 'active'],
                'next_step' => 'set_pin',
            ];
        });
    }

    public function completeVerification(User $user, int $merchantId, ?string $publicInvoiceId): array
    {
        if ($user->isEmployee()) {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can do this', 403);
        }

        $merchant = $user->actingMerchant();

        if (! $merchant || $merchant->id !== $merchantId) {
            throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
        }

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
                app(MerchantProfileService::class)->markPendingWalletsVerified($merchant);
            });
        }

        $wallets = collect(app(MerchantProfileService::class)->wallets($merchant)['wallets'])
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
            ],
        ];
    }

    private function completedMerchant(string $phoneNumber): ?Merchant
    {
        return Merchant::whereIn('phone_number', PhoneNumber::variants($phoneNumber))
            ->whereNotNull('user_id')
            ->where('is_approved', true)
            ->first();
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

    private function usd(int $slsh): array
    {
        $cents = (int) round($slsh / config('exelo.conversion_rate') * 100);

        return ['amount' => $cents, 'currency' => 'USD', 'display' => '$'.number_format($cents / 100, 2)];
    }
}
