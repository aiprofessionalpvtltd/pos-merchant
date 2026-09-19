<?php

namespace App\Services\Sms;

interface SmsSender
{
    public function send(string $e164PhoneNumber, string $message): void;
}
