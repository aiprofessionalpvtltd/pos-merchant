<?php

use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Models\User;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(DatabaseTransactions::class);

const PHONE = '+252634990001';

function device(string $id = 'dev-1'): array
{
    return ['X-EXELO-Device-Id' => $id];
}

function makeMerchant(?string $pin = null): User
{
    $user = User::create([
        'name' => 'Kalid Ahmed',
        'email' => 'kalid-test@example.test',
        'password' => Hash::make($pin ?? 'unusable-default'),
        'user_type' => 'merchant',
        'pin_set_at' => $pin ? now() : null,
    ]);

    $merchant = Merchant::create([
        'user_id' => $user->id,
        'first_name' => 'Kalid',
        'last_name' => 'Ahmed',
        'phone_number' => PHONE,
        'business_name' => 'Exelo Retail',
        'state' => 'Maroodi Jeex',
        'city' => 'Hargeisa',
        'location' => 'Hargeisa, Maroodi Jeex',
        'is_approved' => true,
    ]);

    MerchantSubscription::create([
        'merchant_id' => $merchant->id,
        'subscription_plan_id' => 2,
        'start_date' => now(),
        'end_date' => now()->addMonth(),
        'transaction_status' => 'Paid',
    ]);

    return $user;
}

function login(string $pin = '2580', string $deviceId = 'dev-1')
{
    return test()->postJson('/api/v1/auth/pin/login', ['phone_number' => PHONE, 'pin' => $pin], device($deviceId));
}

it('reports an unknown number without revealing anything', function () {
    $this->postJson('/api/v1/auth/lookup', ['phone_number' => PHONE])
        ->assertOk()
        ->assertJsonPath('data.exists', false)
        ->assertJsonPath('data.has_pin', false);
});

it('looks up a known merchant in any phone format', function () {
    makeMerchant();

    $this->postJson('/api/v1/auth/lookup', ['phone_number' => '0634 990 001'])
        ->assertOk()
        ->assertJsonPath('data.user_type', 'merchant')
        ->assertJsonPath('data.has_pin', false)
        ->assertJsonPath('data.registration.complete', true);
});

it('requires the device id header to log in', function () {
    $this->postJson('/api/v1/auth/pin/login', ['phone_number' => PHONE, 'pin' => '2580'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'request.device_id_missing');
});

it('creates a first PIN, returns a full session and rejects a second create', function () {
    makeMerchant();

    $body = ['phone_number' => PHONE, 'pin' => '2580', 'pin_confirmation' => '2580'];

    $this->postJson('/api/v1/auth/pin', $body, device())
        ->assertCreated()
        ->assertJsonPath('data.user.type', 'merchant')
        ->assertJsonPath('data.merchant.business_name', 'Exelo Retail')
        ->assertJsonPath('data.subscription.plan', 'silver')
        ->assertJsonStructure(['data' => ['token', 'expires_at', 'permissions' => [['key', 'name']]]]);

    $this->postJson('/api/v1/auth/pin', $body, device())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'auth.pin_already_set');
});

it('rejects weak PINs and mismatched confirmation', function () {
    makeMerchant();

    foreach (['1234', '0000', '7777'] as $weak) {
        $this->postJson('/api/v1/auth/pin', ['phone_number' => PHONE, 'pin' => $weak, 'pin_confirmation' => $weak], device())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'auth.pin_too_weak');
    }

    $this->postJson('/api/v1/auth/pin', ['phone_number' => PHONE, 'pin' => '2580', 'pin_confirmation' => '2581'], device())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');
});

it('logs in with the right PIN and counts down wrong attempts before locking', function () {
    makeMerchant('2580');

    login('0001')->assertStatus(401)
        ->assertJsonPath('error.code', 'auth.invalid_credentials')
        ->assertJsonPath('error.details.attempts_remaining', 4);

    login('0002');
    login('0003');
    login('0004')->assertJsonPath('error.details.attempts_remaining', 1);

    login('0005')->assertStatus(423)
        ->assertJsonPath('error.code', 'auth.locked')
        ->assertJsonStructure(['error' => ['details' => ['retry_after']]]);

    login('2580')->assertStatus(423);
});

it('resets the attempt counter after a successful login', function () {
    makeMerchant('2580');

    login('0001');
    login('2580')->assertOk()->assertJsonPath('data.user.first_name', 'Kalid');
    login('0001')->assertJsonPath('error.details.attempts_remaining', 4);
});

it('blocks an unpaid or unapproved merchant from logging in', function () {
    $user = makeMerchant('2580');
    Merchant::where('user_id', $user->id)->update(['is_approved' => false]);

    login('2580')->assertStatus(403)->assertJsonPath('error.code', 'auth.registration_incomplete');
});

it('serves the session and revokes the token on logout', function () {
    makeMerchant('2580');
    $token = login()->json('data.token');

    $this->withToken($token)->getJson('/api/v1/auth/session')
        ->assertOk()
        ->assertJsonPath('data.merchant.city', 'Hargeisa')
        ->assertJsonPath('data.shift.active', false)
        ->assertJsonStructure(['data' => ['server_time', 'merchant' => ['wallets']]]);

    $this->withToken($token)->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('data.revoked_devices', 1);

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/session')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'auth.token_invalid');
});

it('returns 401 without a token', function () {
    $this->getJson('/api/v1/auth/session')->assertStatus(401);
});

it('signs out other devices when the PIN changes but keeps the caller', function () {
    makeMerchant('2580');
    $phone = login('2580', 'dev-phone')->json('data.token');
    $tablet = login('2580', 'dev-tablet')->json('data.token');

    $this->withToken($phone)->patchJson('/api/v1/auth/pin', ['current_pin' => '9999', 'pin' => '1357', 'pin_confirmation' => '1357'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'auth.invalid_credentials');

    $this->withToken($phone)->patchJson('/api/v1/auth/pin', ['current_pin' => '2580', 'pin' => '1357', 'pin_confirmation' => '1357'])
        ->assertOk()
        ->assertJsonPath('data.other_devices_signed_out', true);

    app('auth')->forgetGuards();
    $this->withToken($phone)->getJson('/api/v1/auth/session')->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($tablet)->getJson('/api/v1/auth/session')->assertStatus(401);

    login('1357')->assertOk();
});

it('issues a five minute confirmation token for a verified PIN', function () {
    makeMerchant('2580');
    $token = login()->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/pin/verify', ['pin' => '2580', 'scope' => 'employees.create'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['confirmation_token', 'expires_at']]);

    $this->withToken($token)->postJson('/api/v1/auth/pin/verify', ['pin' => '0000'])->assertStatus(401);
});

it('runs the forgotten PIN flow without ever returning the OTP', function () {
    makeMerchant('2580');
    $stale = login('2580', 'dev-old')->json('data.token');

    $sms = new class implements SmsSender
    {
        public ?string $message = null;

        public function send(string $e164PhoneNumber, string $message): void
        {
            $this->message = $message;
        }
    };
    $this->app->instance(SmsSender::class, $sms);

    $request = $this->postJson('/api/v1/auth/pin/reset/request', ['phone_number' => PHONE])
        ->assertOk()
        ->assertJsonPath('data.masked_phone', '+252 63 *** 0001')
        ->assertJsonMissingPath('data.otp');

    preg_match('/\d{6}/', $sms->message, $match);
    $otp = $match[0];

    $this->postJson('/api/v1/auth/pin/reset/request', ['phone_number' => PHONE])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'auth.otp_throttled');

    $wrong = $otp === '000000' ? '111111' : '000000';
    $this->postJson('/api/v1/auth/pin/reset/verify', ['phone_number' => PHONE, 'otp' => $wrong])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'auth.otp_invalid')
        ->assertJsonPath('error.details.attempts_remaining', 4);

    $reset = $this->postJson('/api/v1/auth/pin/reset/verify', ['phone_number' => PHONE, 'otp' => $otp])
        ->assertOk()
        ->json('data.reset_token');

    $this->postJson('/api/v1/auth/pin/reset', ['reset_token' => $reset, 'pin' => '8642', 'pin_confirmation' => '8642'], device('dev-new'))
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user', 'merchant']]);

    $this->postJson('/api/v1/auth/pin/reset', ['reset_token' => $reset, 'pin' => '8642', 'pin_confirmation' => '8642'], device('dev-new'))
        ->assertStatus(401);

    app('auth')->forgetGuards();
    $this->withToken($stale)->getJson('/api/v1/auth/session')->assertStatus(401);

    login('8642')->assertOk();
});

it('returns the OTP only in a local environment with the flag on', function () {
    makeMerchant('2580');

    config()->set('exelo.expose_otp', true);
    $this->postJson('/api/v1/auth/pin/reset/request', ['phone_number' => PHONE])
        ->assertOk()
        ->assertJsonMissingPath('data.otp');

    Cache::flush();
    app()->detectEnvironment(fn () => 'local');

    $otp = $this->postJson('/api/v1/auth/pin/reset/request', ['phone_number' => PHONE])
        ->assertOk()
        ->assertJsonPath('data.expires_in', 300)
        ->json('data.otp');

    $this->postJson('/api/v1/auth/pin/reset/verify', ['phone_number' => PHONE, 'otp' => $otp])->assertOk();
});

it('does not reveal unknown numbers on reset request', function () {
    $this->postJson('/api/v1/auth/pin/reset/request', ['phone_number' => '+252634990099'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['masked_phone', 'expires_in', 'resend_after']]);
});

it('reports employee accounts and blocks removed staff', function () {
    $owner = makeMerchant('2580');
    $merchant = $owner->merchant;

    $user = User::create(['name' => 'Aisha Ali', 'email' => 'aisha-test@example.test', 'password' => Hash::make('x'), 'user_type' => 'employee']);
    $employee = App\Models\Employee::create([
        'user_id' => $user->id, 'merchant_id' => $merchant->id, 'phone_number' => '+252634990002',
        'first_name' => 'Aisha', 'last_name' => 'Ali', 'dob' => '1995-05-05', 'location' => 'Hargeisa', 'role' => 'Cashier', 'salary' => 100, 'status' => 'active',
    ]);

    $this->postJson('/api/v1/auth/lookup', ['phone_number' => '+252634990002'])
        ->assertJsonPath('data.user_type', 'employee')
        ->assertJsonPath('data.business_name', 'Exelo Retail')
        ->assertJsonPath('data.has_pin', false);

    $employee->update(['status' => 'inactive']);

    $this->postJson('/api/v1/auth/pin/login', ['phone_number' => '+252634990002', 'pin' => '2580'], device())
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'auth.employee_disabled');
});

afterEach(fn () => Cache::flush());
