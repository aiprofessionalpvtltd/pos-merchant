<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Placeholder until an SMS provider is chosen. Message bodies (which carry OTPs)
 * are only written to the log in the local environment.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $e164PhoneNumber, string $message): void
    {
        if (app()->isLocal()) {
            Log::info('SMS to '.$e164PhoneNumber.': '.$message);

            return;
        }

        Log::warning('SMS to '.$e164PhoneNumber.' not sent: no SMS provider is configured');
    }
}
