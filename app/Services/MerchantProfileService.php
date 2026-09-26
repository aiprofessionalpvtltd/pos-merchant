<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\API\V1\MerchantProfileResource;
use App\Models\Merchant;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

class MerchantProfileService
{
    private const RAIL_LABELS = ['zaad' => 'Zaad', 'edahab' => 'eDahab', 'golis' => 'Golis', 'evc' => 'EVC'];

    public function __construct(private readonly FileService $files) {}

    public function profile(Merchant $merchant): array
    {
        return (new MerchantProfileResource($merchant))->resolve();
    }

    /**
     * @param  array<string, mixed>  $data  validated profile fields
     */
    public function updateProfile(Merchant $merchant, array $data, ?string $ifMatch): array
    {
        return DB::transaction(function () use ($merchant, $data, $ifMatch) {
            $merchant = $this->lock($merchant);

            if ($ifMatch !== null && (int) $ifMatch !== $merchant->version) {
                throw new ApiException(
                    'resource.version_conflict',
                    'This shop was changed on another device',
                    409,
                    ['current' => $this->profile($merchant)],
                );
            }

            $merchant->fill(array_intersect_key($data, array_flip([
                'business_name', 'first_name', 'last_name', 'email', 'merchant_code', 'other_merchant_code', 'city',
            ])));

            if (array_key_exists('logo_file_id', $data)) {
                if ($data['logo_file_id'] !== null) {
                    $logo = $this->files->find($merchant, $data['logo_file_id']);

                    if ($logo->purpose !== 'merchant_logo') {
                        throw new ApiException('validation.failed', 'Please check the form', 422, ['logo_file_id' => ['That file was not uploaded as a logo']], 'logo_file_id');
                    }
                }

                $merchant->logo_file_id = $data['logo_file_id'];
                $this->files->markAttached($data['logo_file_id'], $merchant);
            }

            if (isset($data['state'])) {
                $state = collect(config('exelo.states'))->firstWhere('code', $data['state']);
                $merchant->state = $state['name'];
                $merchant->state_code = $state['code'];
            }

            if ($merchant->isDirty(['state', 'city'])) {
                $merchant->location = trim($merchant->city.', '.$merchant->state, ', ');
            }

            $owner = $merchant->user;
            if ($owner->exists) {
                if ($merchant->isDirty(['first_name', 'last_name'])) {
                    $owner->name = trim($merchant->first_name.' '.$merchant->last_name);
                }
                if ($merchant->isDirty('email') && $merchant->email) {
                    $owner->email = $merchant->email;
                }
                $owner->save();
            }

            $this->save($merchant);

            return ['data' => $this->profile($merchant), 'message' => 'Shop details saved'];
        });
    }

    public function wallets(Merchant $merchant): array
    {
        return [
            'wallets' => collect(Merchant::RAILS)->map(fn (string $rail) => $this->walletEntry($merchant, $rail))->all(),
            'default_rail' => $this->defaultRail($merchant),
        ];
    }

    /**
     * The payout wallets of every shop this user can act for: all the shops a merchant owns,
     * or the shops a staff member works in. Each shop has its own wallets and verification.
     *
     * @return array<int, array<string, mixed>>
     */
    public function walletsByShop(User $user): array
    {
        $activeId = $user->actingMerchant()?->id;

        return $user->accessibleShops()
            ->map(function (Merchant $shop) use ($activeId) {
                $data = $this->wallets($shop);
                $wallets = collect($data['wallets']);

                return [
                    'shop_id' => $shop->id,
                    'business_name' => $shop->business_name,
                    'is_active' => $shop->id === $activeId,
                    'wallets' => $data['wallets'],
                    'default_rail' => $data['default_rail'],
                    'verified_rails' => $wallets->where('status', 'verified')->pluck('rail')->values()->all(),
                    'pending_rails' => $wallets->where('status', 'pending')->pluck('rail')->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{rail: string, number: ?string}>  $wallets
     */
    public function updateWallets(Merchant $merchant, array $wallets, ?string $defaultRail): array
    {
        return DB::transaction(function () use ($merchant, $wallets, $defaultRail) {
            $merchant = $this->lock($merchant);
            $states = $merchant->wallet_states ?? [];
            $changed = [];

            foreach ($wallets as $index => $wallet) {
                $rail = $wallet['rail'];
                $column = $rail.'_number';
                $field = "wallets.$index.number";
                $current = $merchant->{$column} ? PhoneNumber::normalize($merchant->{$column}) : null;

                if ($wallet['number'] === null) {
                    $merchant->{$column} = null;
                    unset($states[$rail]);

                    continue;
                }

                $number = PhoneNumber::normalize($wallet['number']);

                if (PhoneNumber::carrier($number) !== $rail) {
                    throw new ApiException('payment.wallet_invalid', 'That number does not belong to '.self::RAIL_LABELS[$rail], 422, [], $field);
                }

                $this->assertWalletFree($merchant, $rail, $number, $field);

                if ($number === $current) {
                    continue;
                }

                $merchant->{$column} = $number;
                $states[$rail] = ['status' => 'pending', 'verified_at' => null, 'rejection_reason' => null];
                $changed[] = $rail;
            }

            $merchant->wallet_states = $states;

            $defaultRail ??= $merchant->default_rail;
            if ($defaultRail !== null && ! $merchant->{$defaultRail.'_number'}) {
                if ($defaultRail === $merchant->default_rail) {
                    $defaultRail = null;
                } else {
                    throw new ApiException('validation.failed', 'Please check the form', 422, ['default_rail' => ['Choose a rail that has a number on file']], 'default_rail');
                }
            }
            $merchant->default_rail = $defaultRail;

            $this->save($merchant);

            $data = $this->wallets($merchant) + ['verification_required' => $changed];

            return ['data' => $data, 'message' => $this->walletMessage($changed)];
        });
    }

    /**
     * Called once a verification fee is paid: numbers waiting for verification are confirmed.
     */
    /**
     * A paid verification fee proves the merchant controls the wallet it was paid from:
     * that wallet becomes the shop's verified payout number on its rail.
     */
    public function verifyWalletFromPayment(Merchant $merchant, string $rail, string $walletNumber): Merchant
    {
        return DB::transaction(function () use ($merchant, $rail, $walletNumber) {
            $merchant = $this->lock($merchant);
            $number = PhoneNumber::normalize($walletNumber);
            $column = $rail.'_number';

            $this->assertWalletFree($merchant, $rail, $number);

            $states = $merchant->wallet_states ?? [];
            $merchant->{$column} = $number;
            $states[$rail] = ['status' => 'verified', 'verified_at' => now()->toIso8601String(), 'rejection_reason' => null];
            $merchant->wallet_states = $states;
            $merchant->default_rail ??= $rail;

            $this->save($merchant);

            return $merchant;
        });
    }

    /**
     * A payout number can't be on another owner's shop. Shops of the same owner may share one.
     */
    public function assertWalletFree(Merchant $merchant, string $rail, string $number, string $field = 'wallet_number'): void
    {
        $isTaken = Merchant::where('id', '!=', $merchant->id)
            ->whereIn($rail.'_number', PhoneNumber::variants($number))
            ->when($merchant->user_id, fn ($query) => $query->where(fn ($owner) => $owner->whereNull('user_id')->orWhere('user_id', '!=', $merchant->user_id)))
            ->exists();

        if ($isTaken) {
            throw new ApiException('wallet.number_taken', 'That number is already used by another shop', 409, [], $field);
        }
    }

    public function markPendingWalletsVerified(Merchant $merchant): void
    {
        $states = $merchant->wallet_states ?? [];

        foreach ($states as $rail => $state) {
            if (($state['status'] ?? null) === 'pending' && $merchant->{$rail.'_number'}) {
                $states[$rail] = ['status' => 'verified', 'verified_at' => now()->toIso8601String(), 'rejection_reason' => null];
            }
        }

        $merchant->wallet_states = $states;
        $merchant->save();
    }

    public function settings(Merchant $merchant): array
    {
        $preferences = array_replace_recursive(config('exelo.preference_defaults'), $merchant->preferences ?? []);
        $vatPercent = rtrim(rtrim(number_format($merchant->vat_rate * 100, 2, '.', ''), '0'), '.');

        return [
            'vat_rate' => $merchant->vat_rate,
            'vat_label' => $vatPercent.'%',
            'vat_inclusive' => $merchant->is_vat_inclusive,
            'exchange_rate' => $merchant->effectiveExchangeRate(),
            'exchange_rate_source' => $merchant->exchange_rate ? 'manual' : 'default',
            'exchange_rate_updated_at' => ApiResponse::iso($merchant->exchange_rate_updated_at),
            'receipt' => $preferences['receipt'],
            'register' => $preferences['register'],
            'alerts' => $preferences['alerts'],
            'timezone' => $merchant->timezone,
            'language' => $merchant->language,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated, nested settings
     */
    public function updateSettings(Merchant $merchant, array $data): array
    {
        if (isset($data['exchange_rate'])) {
            $range = config('exelo.exchange_rate_range');

            if ($data['exchange_rate'] < $range['min'] || $data['exchange_rate'] > $range['max']) {
                throw new ApiException('settings.rate_out_of_range', 'That exchange rate looks wrong. Check it and try again.', 422, $range, 'exchange_rate');
            }
        }

        return DB::transaction(function () use ($merchant, $data) {
            $merchant = $this->lock($merchant);

            if (isset($data['exchange_rate']) && (int) $data['exchange_rate'] !== $merchant->effectiveExchangeRate()) {
                $merchant->exchange_rate = (int) $data['exchange_rate'];
                $merchant->exchange_rate_updated_at = now();
            }

            foreach (['vat_rate', 'timezone', 'language'] as $key) {
                if (array_key_exists($key, $data)) {
                    $merchant->{$key} = $data[$key];
                }
            }

            if (array_key_exists('vat_inclusive', $data)) {
                $merchant->is_vat_inclusive = $data['vat_inclusive'];
            }

            $groups = array_intersect_key($data, config('exelo.preference_defaults'));
            if ($groups) {
                $merchant->preferences = array_replace_recursive($merchant->preferences ?? [], $groups);
            }

            $this->save($merchant);

            return ['data' => $this->settings($merchant), 'message' => 'Settings saved'];
        });
    }

    private function walletEntry(Merchant $merchant, string $rail): array
    {
        $number = $merchant->{$rail.'_number'};
        $state = ($merchant->wallet_states ?? [])[$rail] ?? null;

        $status = 'not_set';
        if ($number) {
            $status = $state['status'] ?? 'verified';
        }

        $entry = [
            'rail' => $rail,
            'label' => self::RAIL_LABELS[$rail],
            'number' => $number ? PhoneNumber::normalize($number) : null,
            'status' => $status,
            'verified_at' => $status === 'verified' ? ($state['verified_at'] ?? null) : null,
            'is_default' => $number && $this->defaultRail($merchant) === $rail,
        ];

        if ($status === 'rejected') {
            $entry['rejection_reason'] = $state['rejection_reason'] ?? null;
        }

        return $entry;
    }

    private function defaultRail(Merchant $merchant): ?string
    {
        if ($merchant->default_rail && $merchant->{$merchant->default_rail.'_number'}) {
            return $merchant->default_rail;
        }

        foreach (Merchant::RAILS as $rail) {
            if ($merchant->{$rail.'_number'}) {
                return $rail;
            }
        }

        return null;
    }

    private function walletMessage(array $changed): string
    {
        if (count($changed) === 1) {
            return self::RAIL_LABELS[$changed[0]].' number saved. Verify it to start receiving payments there.';
        }

        if ($changed) {
            $labels = collect($changed)->map(fn (string $rail) => self::RAIL_LABELS[$rail])->implode(', ');

            return "Wallet numbers saved. Verify $labels to start receiving payments there.";
        }

        return 'Wallets saved';
    }

    private function lock(Merchant $merchant): Merchant
    {
        return Merchant::whereKey($merchant->id)->lockForUpdate()->firstOrFail();
    }

    private function save(Merchant $merchant): void
    {
        if ($merchant->isDirty()) {
            $merchant->version++;
            $merchant->save();
        }
    }
}
