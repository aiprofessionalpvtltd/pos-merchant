<?php

namespace App\Support;

class PhoneNumber
{
    private const COUNTRY_CODE = '252';

    /**
     * Normalise local, 252-prefixed and +252 input to E.164 (+252XXXXXXXXX).
     */
    public static function normalize(string $input): string
    {
        $digits = preg_replace('/\D/', '', $input);

        if (str_starts_with($digits, '00'.self::COUNTRY_CODE)) {
            $digits = substr($digits, 2);
        }

        if (! str_starts_with($digits, self::COUNTRY_CODE)) {
            $digits = self::COUNTRY_CODE.ltrim($digits, '0');
        }

        return '+'.$digits;
    }

    /**
     * Every format the legacy API may have stored for the same number.
     *
     * @return array<int, string>
     */
    public static function variants(string $input): array
    {
        $e164 = self::normalize($input);
        $international = substr($e164, 1);
        $local = substr($international, strlen(self::COUNTRY_CODE));

        return [$e164, $international, $local, '0'.$local];
    }

    /**
     * Wallet the number belongs to: zaad, edahab, golis, evc or null.
     */
    public static function carrier(string $input): ?string
    {
        $local = substr(self::normalize($input), 1 + strlen(self::COUNTRY_CODE));

        return match (true) {
            in_array(substr($local, 0, 2), ['65', '66', '62'], true) => 'edahab',
            str_starts_with($local, '63') => 'zaad',
            str_starts_with($local, '90') => 'golis',
            str_starts_with($local, '61') => 'evc',
            default => null,
        };
    }

    public static function mask(string $e164): string
    {
        $digits = substr($e164, 1);
        $local = substr($digits, strlen(self::COUNTRY_CODE));

        return '+'.self::COUNTRY_CODE.' '.substr($local, 0, 2).' *** '.substr($local, -4);
    }
}
