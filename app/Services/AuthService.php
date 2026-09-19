<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\API\V1\SessionResource;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Otp;
use App\Models\User;
use App\Services\Sms\SmsSender;
use App\Support\ApiResponse;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(private readonly SmsSender $sms) {}

    public function lookup(string $phoneNumber): array
    {
        $account = $this->resolveAccount($phoneNumber);

        if (! $account) {
            return [
                'message' => 'Create an account to continue',
                'data' => ['exists' => false, 'user_type' => null, 'has_pin' => false],
            ];
        }

        $user = $account['user'];
        $merchant = $account['merchant'];
        $profile = $account['employee'] ?? $merchant;

        if ($account['employee']) {
            $isComplete = true;
            $isInvoiceRequired = false;
        } else {
            $isComplete = $user !== null && $merchant->is_approved;
            $isInvoiceRequired = ! $isComplete && ! $this->hasPaidRegistrationInvoice($phoneNumber);
        }

        return [
            'message' => 'Enter your PIN',
            'data' => [
                'exists' => true,
                'user_type' => $account['employee'] ? 'employee' : 'merchant',
                'has_pin' => $user?->hasPin() ?? false,
                'display_name' => trim($profile->first_name.' '.$profile->last_name),
                'business_name' => $merchant?->business_name,
                'registration' => ['complete' => $isComplete, 'invoice_required' => $isInvoiceRequired],
            ],
        ];
    }

    public function loginWithPin(string $phoneNumber, string $pin, string $deviceId): array
    {
        $account = $this->resolveAccount($phoneNumber);

        if (! $account) {
            throw new ApiException('auth.invalid_credentials', 'That PIN is not correct.', 401);
        }

        $user = $this->assertCanSignIn($account);

        if (! $user->hasPin()) {
            throw new ApiException('auth.pin_not_set', 'Create a PIN to continue', 409);
        }

        $this->assertPinCorrect($user, $pin);

        return $this->issueSession($user, $deviceId);
    }

    public function createPin(string $phoneNumber, string $pin, string $deviceId): array
    {
        $account = $this->resolveAccount($phoneNumber);

        if (! $account) {
            throw new ApiException('validation.failed', 'Please check the form', 422, [
                'phone_number' => ['This number is not registered'],
            ], 'phone_number');
        }

        $user = $this->assertCanSignIn($account);

        if ($user->hasPin()) {
            throw new ApiException('auth.pin_already_set', 'A PIN is already set. Reset it instead.', 409);
        }

        $this->assertPinStrong($pin);
        $this->storePin($user, $pin);

        return $this->issueSession($user, $deviceId);
    }

    public function changePin(User $user, string $currentPin, string $newPin, ?string $currentTokenId): array
    {
        $this->assertPinCorrect($user, $currentPin);
        $this->assertPinStrong($newPin);

        $this->storePin($user, $newPin);

        $revoked = $this->revokeTokens(
            $user->tokens()->where('revoked', false)->when($currentTokenId, fn ($query) => $query->where('id', '!=', $currentTokenId))
        );

        return [
            'changed_at' => ApiResponse::iso(now()),
            'other_devices_signed_out' => $revoked > 0,
        ];
    }

    public function verifyPin(User $user, string $pin, ?string $scope): array
    {
        $this->assertPinCorrect($user, $pin);

        $token = 'cnf_'.Str::upper(Str::random(20));
        $expiresAt = now()->addSeconds(config('exelo.confirmation_ttl_seconds'));

        Cache::put('pin-confirmation:'.$token, ['user_id' => $user->id, 'scope' => $scope], $expiresAt);

        Log::info('PIN re-confirmed', ['user_id' => $user->id, 'scope' => $scope]);

        return ['confirmation_token' => $token, 'expires_at' => ApiResponse::iso($expiresAt)];
    }

    /**
     * Checks a PIN confirmation token without using it, so a request that fails
     * validation later does not cost the merchant another PIN entry.
     */
    public function assertConfirmation(User $user, ?string $token, string $scope): void
    {
        $confirmation = $token ? Cache::get('pin-confirmation:'.$token) : null;

        $isValid = $confirmation
            && $confirmation['user_id'] === $user->id
            && ($confirmation['scope'] === null || $confirmation['scope'] === $scope);

        if (! $isValid) {
            throw new ApiException('auth.confirmation_required', 'Enter your PIN to continue', 401);
        }
    }

    /**
     * Uses a confirmation token: each PIN entry authorises exactly one action.
     */
    public function consumeConfirmation(User $user, ?string $token, string $scope): void
    {
        $this->assertConfirmation($user, $token, $scope);

        Cache::forget('pin-confirmation:'.$token);
    }

    public function requestPinReset(string $phoneNumber): array
    {
        $e164 = PhoneNumber::normalize($phoneNumber);
        $ttl = config('exelo.otp.ttl_seconds');
        $resendAfter = config('exelo.otp.resend_after_seconds');

        $response = [
            'masked_phone' => PhoneNumber::mask($e164),
            'expires_in' => $ttl,
            'resend_after' => $resendAfter,
        ];

        $account = $this->resolveAccount($phoneNumber);
        $user = $account['user'] ?? null;

        // An unknown number gets the same answer so the endpoint cannot enumerate accounts.
        if (! $user || $this->isDisabled($account)) {
            return $response;
        }

        $throttleKey = 'pin-reset-sent:'.$user->id;
        $availableAt = Cache::get($throttleKey);

        if ($availableAt && $availableAt > now()->timestamp) {
            throw new ApiException('auth.otp_throttled', 'Please wait before requesting another code', 429, [
                'retry_after' => $availableAt - now()->timestamp,
            ]);
        }

        $length = config('exelo.otp.length');
        $otp = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $otp, $ttl) {
            Otp::where('user_id', $user->id)->delete();
            Otp::create(['user_id' => $user->id, 'otp' => Hash::make($otp), 'expires_at' => now()->addSeconds($ttl)]);
        });

        Cache::put($throttleKey, now()->timestamp + $resendAfter, $resendAfter);
        Cache::forget('pin-reset-attempts:'.$user->id);

        $this->sms->send($e164, "Your EXELO code is {$otp}. It expires in ".intdiv($ttl, 60).' minutes.');

        // Local development only: lets you finish the reset flow without an SMS provider.
        if (app()->environment('local') && config('exelo.expose_otp')) {
            $response['otp'] = $otp;
        }

        return $response;
    }

    public function verifyPinResetOtp(string $phoneNumber, string $otp): array
    {
        $account = $this->resolveAccount($phoneNumber);
        $user = $account['user'] ?? null;

        if (! $user || $this->isDisabled($account)) {
            throw new ApiException('auth.otp_invalid', 'That code is not correct', 422);
        }

        $record = Otp::where('user_id', $user->id)->latest('id')->first();

        if (! $record || $record->expires_at->isPast()) {
            $record?->delete();

            throw new ApiException('auth.otp_expired', 'That code has expired. Request a new one.', 410);
        }

        if (! Hash::check($otp, $record->otp)) {
            $attemptsKey = 'pin-reset-attempts:'.$user->id;
            $attempts = Cache::increment($attemptsKey);
            Cache::put($attemptsKey, $attempts, $record->expires_at);
            $remaining = max(0, config('exelo.otp.max_attempts') - $attempts);

            if ($remaining === 0) {
                $record->delete();
            }

            throw new ApiException('auth.otp_invalid', 'That code is not correct', 422, ['attempts_remaining' => $remaining]);
        }

        $record->delete();
        Cache::forget('pin-reset-attempts:'.$user->id);

        $token = 'rst_'.Str::upper(Str::random(20));
        $expiresAt = now()->addSeconds(config('exelo.otp.reset_token_ttl_seconds'));

        Cache::put('pin-reset-token:'.$token, $user->id, $expiresAt);

        return ['reset_token' => $token, 'expires_at' => ApiResponse::iso($expiresAt)];
    }

    public function resetPin(string $resetToken, string $pin, string $deviceId): array
    {
        $userId = Cache::get('pin-reset-token:'.$resetToken);
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            throw new ApiException('auth.token_invalid', 'This reset link has expired. Start again.', 401);
        }

        $this->assertPinStrong($pin);

        Cache::forget('pin-reset-token:'.$resetToken);
        $this->storePin($user, $pin);
        $this->revokeTokens($user->tokens()->where('revoked', false));

        return $this->issueSession($user, $deviceId);
    }

    public function session(User $user): array
    {
        return (new SessionResource($user, true))->resolve();
    }

    public function logout(User $user, ?string $currentTokenId, bool $isAllDevices): int
    {
        $tokens = $user->tokens()->where('revoked', false);

        if (! $isAllDevices) {
            $tokens->where('id', $currentTokenId);
        }

        return $this->revokeTokens($tokens);
    }

    /**
     * @return array{user: ?User, merchant: ?Merchant, employee: ?Employee}|null
     */
    private function resolveAccount(string $phoneNumber): ?array
    {
        $variants = PhoneNumber::variants($phoneNumber);

        $merchant = Merchant::whereIn('phone_number', $variants)->first();

        if ($merchant) {
            $user = $merchant->user_id ? User::find($merchant->user_id) : null;

            return ['user' => $user, 'merchant' => $merchant, 'employee' => null];
        }

        $employee = Employee::whereIn('phone_number', $variants)->first();

        if ($employee) {
            return [
                'user' => User::withTrashed()->find($employee->user_id),
                'merchant' => $employee->merchant,
                'employee' => $employee,
            ];
        }

        return null;
    }

    private function isDisabled(array $account): bool
    {
        $employee = $account['employee'];

        return $employee !== null && ($employee->status === 'inactive' || $account['user']?->trashed());
    }

    private function assertCanSignIn(array $account): User
    {
        if ($this->isDisabled($account) || ($account['employee'] && ! $account['user'])) {
            throw new ApiException('auth.employee_disabled', 'This staff account was removed', 403);
        }

        if (! $account['employee'] && (! $account['user'] || ! $account['merchant']->is_approved)) {
            throw new ApiException('auth.registration_incomplete', 'Finish registering to continue', 403);
        }

        return $account['user'];
    }

    private function hasPaidRegistrationInvoice(string $phoneNumber): bool
    {
        return Invoice::paid()
            ->where('type', 'Registration')
            ->whereIn('mobile_number', PhoneNumber::variants($phoneNumber))
            ->exists();
    }

    private function assertPinCorrect(User $user, string $pin): void
    {
        if ($user->locked_until && $user->locked_until->isFuture()) {
            throw $this->lockedException($user);
        }

        if (Hash::check($pin, $user->password)) {
            if ($user->pin_failed_attempts > 0 || $user->locked_until) {
                $user->forceFill(['pin_failed_attempts' => 0, 'locked_until' => null])->save();
            }

            return;
        }

        $maxAttempts = config('exelo.pin.max_attempts');
        $attempts = $user->locked_until ? 1 : $user->pin_failed_attempts + 1;

        if ($attempts >= $maxAttempts) {
            $user->forceFill([
                'pin_failed_attempts' => 0,
                'locked_until' => now()->addMinutes(config('exelo.pin.lockout_minutes')),
            ])->save();

            throw $this->lockedException($user);
        }

        $user->forceFill(['pin_failed_attempts' => $attempts, 'locked_until' => null])->save();
        $remaining = $maxAttempts - $attempts;

        throw new ApiException(
            'auth.invalid_credentials',
            'That PIN is not correct. '.$remaining.' '.Str::plural('attempt', $remaining).' left.',
            401,
            ['attempts_remaining' => $remaining],
        );
    }

    private function lockedException(User $user): ApiException
    {
        $retryAfter = max(1, now()->diffInSeconds($user->locked_until, false));

        return new ApiException('auth.locked', 'Too many wrong PINs. Try again later.', 423, ['retry_after' => (int) $retryAfter]);
    }

    private function assertPinStrong(string $pin): void
    {
        $isRepeated = count(array_unique(str_split($pin))) === 1;

        if ($isRepeated || in_array($pin, config('exelo.pin.weak_pins'), true)) {
            throw new ApiException('auth.pin_too_weak', 'Choose a PIN that is harder to guess', 422, [], 'pin');
        }
    }

    private function storePin(User $user, string $pin): void
    {
        $user->forceFill([
            'password' => Hash::make($pin),
            'pin' => null,
            'pin_set_at' => now(),
            'pin_failed_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }

    private function issueSession(User $user, string $deviceId): array
    {
        $tokenName = 'device:'.$deviceId;

        $this->revokeTokens($user->tokens()->where('name', $tokenName)->where('revoked', false));

        $result = $user->createToken($tokenName);

        return [
            'token' => $result->accessToken,
            'expires_at' => ApiResponse::iso($result->token->expires_at),
        ] + (new SessionResource($user))->resolve();
    }

    private function revokeTokens($query): int
    {
        $tokens = $query->get();

        $tokens->each->revoke();

        return $tokens->count();
    }
}
