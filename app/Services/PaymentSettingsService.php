<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The registration and verification fees, set by an admin in the settings table.
 * A fee left empty there falls back to config('exelo.registration.fees').
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
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->first()?->only($this->columns()) ?? []);

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
     * @param  array<string, array{base: int, fee: int}>  $fees
     */
    public function update(array $fees, int $adminId): void
    {
        $setting = Setting::query()->first();

        if (! $setting) {
            throw new ApiException('settings.missing', 'The settings record is missing. Run: php artisan db:seed --class=SettingSeeder', 409);
        }

        $before = $this->all();

        $setting->update([
            'registration_fee' => $fees['registration']['base'],
            'registration_fee_charge' => $fees['registration']['fee'],
            'verification_fee' => $fees['verification']['base'],
            'verification_fee_charge' => $fees['verification']['fee'],
        ]);

        Cache::forget(self::CACHE_KEY);

        Log::info('Payment fees changed by admin', ['before' => $before, 'after' => $this->all(), 'changed_by' => $adminId]);
    }

    /**
     * @return array<int, string>
     */
    private function columns(): array
    {
        return collect(self::PURPOSES)->flatMap(fn (string $purpose) => [$purpose.'_fee', $purpose.'_fee_charge'])->all();
    }
}
