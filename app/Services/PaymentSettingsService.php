<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fees an admin sets in the settings table.
 * Registration and verification fall back to config('exelo.registration.fees').
 * The sales fee is a percent of the sale total and falls back to the wallet fee rate (2.85%).
 * GST is a percent of the cart total and falls back to 2.5%.
 */
class PaymentSettingsService
{
    public const PURPOSES = ['registration', 'verification'];

    private const CACHE_KEY = 'settings:payment-fees';

    /**
     * @return array{base: int, fee: int}
     */
    public function fees(string $purpose): array
    {
        return $this->all()[$purpose];
    }

    /**
     * @return array<string, array{base: int, fee: int, is_default: bool}>
     */
    public function all(): array
    {
        $stored = $this->stored();

        $fees = [];

        foreach (self::PURPOSES as $purpose) {
            $default = config('exelo.registration.fees.'.$purpose);
            $base = $stored[$purpose.'_fee'] ?? null;
            $fee = $stored[$purpose.'_fee_charge'] ?? null;

            $fees[$purpose] = [
                'base' => $base ?? $default['base'],
                'fee' => $fee ?? $default['fee'],
                'is_default' => $base === null && $fee === null,
            ];
        }

        return $fees;
    }

    /**
     * The EXELO sales fee, as a percent of the sale total (2.85 means 2.85%).
     *
     * @return array{percent: float, is_default: bool}
     */
    public function salesFee(): array
    {
        $stored = $this->stored()['sales_fee_percent'] ?? null;

        return [
            'percent' => $stored === null ? $this->defaultSalesFeePercent() : (float) $stored,
            'is_default' => $stored === null,
        ];
    }

    /**
     * The sales fee as a rate (2.85% is 0.0285), applied when a checkout sale is paid.
     */
    public function salesFeeRate(): float
    {
        return $this->salesFee()['percent'] / 100;
    }

    /**
     * GST, as a percent of the cart total (2.5 means 2.5%).
     *
     * @return array{percent: float, is_default: bool}
     */
    public function gst(): array
    {
        $stored = $this->stored()['gst_percent'] ?? null;

        return [
            'percent' => $stored === null ? $this->defaultGstPercent() : (float) $stored,
            'is_default' => $stored === null,
        ];
    }

    /**
     * GST as a rate (2.5% is 0.025), applied to the cart total.
     */
    public function gstRate(): float
    {
        return $this->gst()['percent'] / 100;
    }

    /**
     * @param  array<string, array<string, int|float|string>>  $fees
     */
    public function update(array $fees, int $adminId): void
    {
        $setting = Setting::query()->first();

        if (! $setting) {
            throw new ApiException('settings.missing', 'The settings record is missing. Run: php artisan db:seed --class=SettingSeeder', 409);
        }

        $before = [
            'fees' => $this->all(),
            'sales_fee_percent' => $this->salesFee()['percent'],
            'gst_percent' => $this->gst()['percent'],
        ];

        $setting->update([
            'registration_fee' => $fees['registration']['base'],
            'registration_fee_charge' => $fees['registration']['fee'],
            'verification_fee' => $fees['verification']['base'],
            'verification_fee_charge' => $fees['verification']['fee'],
            'sales_fee_percent' => round((float) $fees['sales']['percent'], 2),
            'gst_percent' => round((float) $fees['gst']['percent'], 2),
        ]);

        Cache::forget(self::CACHE_KEY);

        Log::info('Payment fees changed by admin', [
            'before' => $before,
            'after' => [
                'fees' => $this->all(),
                'sales_fee_percent' => $this->salesFee()['percent'],
                'gst_percent' => $this->gst()['percent'],
            ],
            'changed_by' => $adminId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->first()?->only([...$this->columns(), 'sales_fee_percent', 'gst_percent']) ?? []);
    }

    private function defaultGstPercent(): float
    {
        return round((float) config('exelo.payments.gst_percent'), 2);
    }

    private function defaultSalesFeePercent(): float
    {
        return round((float) config('exelo.payments.wallet_fee_rate') * 100, 2);
    }

    /**
     * @return array<int, string>
     */
    private function columns(): array
    {
        return collect(self::PURPOSES)->flatMap(fn (string $purpose) => [$purpose.'_fee', $purpose.'_fee_charge'])->all();
    }
}
