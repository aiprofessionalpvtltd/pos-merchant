<?php

namespace App\Support;

class Money
{
    /**
     * @return array{amount: int, currency: string, display: string}
     */
    public static function format(int $amount, string $currency): array
    {
        return ['amount' => $amount, 'currency' => $currency, 'display' => number_format($amount).' '.$currency];
    }

    /**
     * Money in the smallest unit of its currency: cents for USD, whole units for SLSH.
     *
     * @return array{amount: int, currency: string, display: string}
     */
    public static function of(int $minorUnits, string $currency): array
    {
        return strtoupper($currency) === 'USD' ? self::usd($minorUnits) : self::format($minorUnits, strtoupper($currency));
    }

    /**
     * Converts a major-unit decimal (as stored in the database) to minor units.
     */
    public static function toMinor(float $major, string $currency): int
    {
        return (int) round(strtoupper($currency) === 'USD' ? $major * 100 : $major);
    }

    /**
     * USD amounts are integer cents.
     *
     * @return array{amount: int, currency: string, display: string}
     */
    public static function usd(int $cents): array
    {
        return ['amount' => $cents, 'currency' => 'USD', 'display' => '$'.number_format($cents / 100, 2)];
    }
}
