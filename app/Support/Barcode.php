<?php

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Support\Str;

class Barcode
{
    /**
     * Letters and digits only, upper case: "rce-005" becomes "RCE005".
     */
    public static function normalise(string $raw): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
    }

    /**
     * Every spelling a code may be stored under. Numeric codes also match their
     * GTIN-8/12/13/14 zero-padded forms, so a scanner that drops leading zeros still finds the product.
     *
     * @return array<int, string>
     */
    public static function candidates(string $raw): array
    {
        $normalised = self::normalise($raw);
        $candidates = [$raw, $normalised];

        if ($normalised !== '' && ctype_digit($normalised)) {
            $trimmed = ltrim($normalised, '0') ?: '0';

            foreach ([8, 12, 13, 14] as $length) {
                if (strlen($trimmed) <= $length) {
                    $candidates[] = str_pad($trimmed, $length, '0', STR_PAD_LEFT);
                }
            }

            $candidates[] = $trimmed;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Restricts a products query to those whose bar code matches $raw in any spelling.
     */
    public static function match(BuilderContract $query, string $raw): void
    {
        $candidates = self::candidates($raw);
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));

        $query->where(function ($inner) use ($candidates, $placeholders) {
            $inner->whereIn('bar_code', $candidates)
                ->orWhereRaw("UPPER(REPLACE(REPLACE(bar_code, '-', ''), ' ', '')) IN ($placeholders)", $candidates);
        });
    }
}
