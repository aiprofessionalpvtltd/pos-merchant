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
     * USD amounts are integer cents.
     *
     * @return array{amount: int, currency: string, display: string}
     */
    public static function usd(int $cents): array
    {
        return ['amount' => $cents, 'currency' => 'USD', 'display' => '$'.number_format($cents / 100, 2)];
    }
}
