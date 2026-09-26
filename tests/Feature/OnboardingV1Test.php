<?php

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(function () {
    (new PlanCatalogueSeeder)->run();

    Http::fake([
        'edahab.net/api/api/IssueInvoice*' => Http::response(fixture('edahab/check-invoice-pending.json')),
        'edahab.net/api/api/checkInvoiceStatus*' => Http::response(['InvoiceStatus' => 'Paid', 'TransactionId' => 'MP260926.1000.A00002', 'StatusCode' => 0]),
    ]);
});

const MERCHANT_PHONE = '+252654990101';
const FIRST_SHOP_PHONE = '+252634990102';
const SECOND_SHOP_PHONE_ONB = '+252634990103';
const ONB_DEVICE = ['X-EXELO-Device-Id' => 'onboarding-till'];

function onboardingPay(string $purpose, string $key, string $wallet = MERCHANT_PHONE): string
{
    $quote = test()->getJson('/api/v1/registration/quote?purpose='.$purpose)->json('data.quote_id');

    $invoiceId = test()->postJson('/api/v1/registration/invoices', [
        'phone_number' => MERCHANT_PHONE, 'wallet_number' => $wallet, 'rail' => 'edahab',
        'purpose' => $purpose, 'quote_id' => $quote, 'idempotency_key' => $key,
    ])->assertStatus(202)->json('data.invoice_id');

    test()->getJson('/api/v1/registration/invoices/'.$invoiceId)->assertJsonPath('data.status', 'paid');

    return $invoiceId;
}

function merchantAccount(): string
{
    $invoiceId = onboardingPay('registration', 'reg-key');

    test()->postJson('/api/v1/merchants', [
        'invoice_id' => $invoiceId, 'first_name' => 'Sahra', 'last_name' => 'Nur', 'dob' => '1991-03-03',
        'phone_number' => MERCHANT_PHONE,
    ])->assertCreated()
        ->assertJsonPath('data.merchant', null)
        ->assertJsonPath('data.user.phone_number', MERCHANT_PHONE)
        ->assertJsonPath('data.next_step', 'set_pin');

    return test()->postJson('/api/v1/auth/pin', ['phone_number' => MERCHANT_PHONE, 'pin' => '2580', 'pin_confirmation' => '2580'], ONB_DEVICE)
        ->assertCreated()
        ->assertJsonPath('data.merchant', null)
        ->assertJsonPath('data.shops', [])
        ->assertJsonPath('data.onboarding', ['phone_verified' => false, 'next_step' => 'verify_phone'])
        ->json('data.token');
}

it('onboards a merchant: account, PIN, verify phone, then a free first shop with its own number', function () {
    $token = merchantAccount();

    // The account exists without a shop; creating one is blocked until the phone is verified.
    test()->withToken($token)->postJson('/api/v1/shops', ['business_name' => 'Sahra Store', 'phone_number' => FIRST_SHOP_PHONE, 'state' => 'maroodi_jeex', 'city' => 'Hargeisa'])
        ->assertStatus(409)->assertJsonPath('error.code', 'account.phone_unverified');

    // Verify the merchant's own number: a separate payment, from that number.
    $verificationId = onboardingPay('verification', 'verify-key');

    app('auth')->forgetGuards();
    test()->withToken($token)->postJson('/api/v1/account/verification/complete', ['invoice_id' => $verificationId])
        ->assertOk()
        ->assertJsonPath('data', ['phone_number' => MERCHANT_PHONE, 'phone_verified' => true, 'next_step' => 'create_shop']);

    // The first shop is free, becomes active at once, and receives the verified number as its payout wallet.
    $invoices = Invoice::count();

    app('auth')->forgetGuards();
    $shopId = test()->withToken($token)->postJson('/api/v1/shops', ['business_name' => 'Sahra Store', 'phone_number' => FIRST_SHOP_PHONE, 'state' => 'maroodi_jeex', 'city' => 'Hargeisa'])
        ->assertCreated()
        ->assertJsonPath('message', 'Sahra Store is ready. You can start selling.')
        ->assertJsonPath('data.shop.is_active', true)
        ->assertJsonPath('data.payout_wallets.1', ['rail' => 'edahab', 'label' => 'eDahab', 'number' => MERCHANT_PHONE, 'status' => 'verified', 'verified_at' => Merchant::latest('id')->first()->wallet_states['edahab']['verified_at'], 'is_default' => true])
        ->json('data.shop.id');

    $shop = Merchant::find($shopId);

    expect(Invoice::count())->toBe($invoices)
        // Every shop stores its own full set of preferences from the start.
        ->and($shop->preferences)->toEqual(config('exelo.preference_defaults'))
        ->and($shop->phone_number)->toBe(FIRST_SHOP_PHONE)
        ->and($shop->only(['first_name', 'last_name']))->toBe(['first_name' => 'Sahra', 'last_name' => 'Nur']);

    // Every call now acts for the new shop; onboarding is done.
    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/auth/session')
        ->assertOk()
        ->assertJsonPath('data.merchant.id', $shopId)
        ->assertJsonPath('data.user.phone_number', MERCHANT_PHONE)
        ->assertJsonPath('data.onboarding', ['phone_verified' => true, 'next_step' => null]);

    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/payments/methods')->assertJsonPath('data.accepts', ['edahab', 'cash']);

    // A second shop is free too, and shares the owner's verified payout number.
    app('auth')->forgetGuards();
    $second = test()->withToken($token)->postJson('/api/v1/shops', ['business_name' => 'Sahra Two', 'phone_number' => SECOND_SHOP_PHONE_ONB, 'state' => 'sahil', 'city' => 'Berbera'])
        ->assertCreated()
        ->assertJsonPath('data.shop.is_active', false)
        ->json('data.shop.id');

    expect(Merchant::find($second)->edahab_number)->toBe(MERCHANT_PHONE)
        ->and(Invoice::count())->toBe($invoices);
});

it('shows one merchant with all their shops and each shop\'s details, from separate tables', function () {
    $token = merchantAccount();

    app('auth')->forgetGuards();
    test()->withToken($token)->postJson('/api/v1/account/verification/complete', ['invoice_id' => onboardingPay('verification', 'verify-2')])->assertOk();

    foreach ([[FIRST_SHOP_PHONE, 'Sahra Store', 'maroodi_jeex', 'Hargeisa'], [SECOND_SHOP_PHONE_ONB, 'Sahra Two', 'sahil', 'Berbera']] as [$phone, $name, $state, $city]) {
        app('auth')->forgetGuards();
        test()->withToken($token)->postJson('/api/v1/shops', ['business_name' => $name, 'phone_number' => $phone, 'state' => $state, 'city' => $city])->assertCreated();
    }

    app('auth')->forgetGuards();
    $account = test()->withToken($token)->getJson('/api/v1/account')
        ->assertOk()
        ->assertJsonPath('data.merchant.first_name', 'Sahra')
        ->assertJsonPath('data.merchant.phone_number', MERCHANT_PHONE)
        ->assertJsonPath('data.merchant.phone_verified', true)
        ->assertJsonPath('data.shops_count', 2)
        ->assertJsonPath('data.shops.0.business_name', 'Sahra Store')
        ->assertJsonPath('data.shops.0.phone_number', FIRST_SHOP_PHONE)
        ->assertJsonPath('data.shops.0.is_active', true)
        ->assertJsonPath('data.shops.0.subscription.plan', 'silver')
        ->assertJsonPath('data.shops.0.staff_count', 0)
        ->assertJsonPath('data.shops.1.address.location', 'Berbera, Sahil')
        ->assertJsonPath('data.shops.1.is_active', false)
        ->assertJsonPath('data.onboarding.next_step', null)
        ->json('data');

    // Separate tables: one merchants row, two shops rows pointing at it.
    $merchant = App\Models\MerchantAccount::where('phone_number', MERCHANT_PHONE)->first();

    expect($account['merchant']['id'])->toBe($merchant->id)
        ->and($merchant->shops()->pluck('phone_number')->all())->toBe([FIRST_SHOP_PHONE, SECOND_SHOP_PHONE_ONB])
        ->and(collect($account['shops'])->firstWhere('id', $merchant->shops()->first()->id)['payout_wallets'][1]['status'])->toBe('verified');

    // The merchant edits their own details; the shops keep theirs.
    app('auth')->forgetGuards();
    test()->withToken($token)->patchJson('/api/v1/account', ['first_name' => 'Sahro', 'email' => 'sahro@example.com'])
        ->assertOk()
        ->assertJsonPath('message', 'Your details are saved')
        ->assertJsonPath('data.merchant.first_name', 'Sahro')
        ->assertJsonPath('data.merchant.email', 'sahro@example.com')
        ->assertJsonPath('data.shops.0.business_name', 'Sahra Store');

    expect($merchant->fresh()->first_name)->toBe('Sahro')
        ->and($merchant->user->fresh()->name)->toBe('Sahro Nur');
});

it('keeps staff out of the merchant account', function () {
    $shop = Merchant::create(['phone_number' => '+252634990150', 'business_name' => 'X', 'is_approved' => true]);
    $staff = User::create(['name' => 'Staff', 'email' => 'onb-staff@example.test', 'password' => 'x', 'user_type' => 'employee']);
    App\Models\Employee::create(['user_id' => $staff->id, 'merchant_id' => $shop->id, 'phone_number' => '+252634990151', 'first_name' => 'S', 'last_name' => 'T', 'dob' => '1990-01-01', 'role' => 'Cashier', 'status' => 'active']);

    test()->actingAs($staff, 'api')->getJson('/api/v1/account')->assertForbidden()->assertJsonPath('error.code', 'auth.merchant_only');
});

it('signs the merchant in with their own number, not the shop\'s', function () {
    $token = merchantAccount();

    test()->postJson('/api/v1/auth/lookup', ['phone_number' => MERCHANT_PHONE])
        ->assertOk()
        ->assertJsonPath('data.exists', true)
        ->assertJsonPath('data.has_pin', true)
        ->assertJsonPath('data.display_name', 'Sahra Nur')
        ->assertJsonPath('data.business_name', null);

    test()->postJson('/api/v1/auth/pin/login', ['phone_number' => MERCHANT_PHONE, 'pin' => '2580'], ONB_DEVICE)
        ->assertOk()
        ->assertJsonPath('data.onboarding.next_step', 'verify_phone');
});

it('makes the merchant verify from their own number', function () {
    merchantAccount();

    $quote = test()->getJson('/api/v1/registration/quote?purpose=verification')->json('data.quote_id');

    test()->postJson('/api/v1/registration/invoices', [
        'phone_number' => MERCHANT_PHONE, 'wallet_number' => '+252654990199', 'rail' => 'edahab',
        'purpose' => 'verification', 'quote_id' => $quote, 'idempotency_key' => 'other-wallet',
    ])->assertStatus(422)->assertJsonPath('error.details.wallet_number.0', 'Pay from the number you are verifying');
});

it('keeps the merchant\'s own number out of shops and new accounts', function () {
    merchantAccount();

    test()->postJson('/api/v1/registration/phone/check', ['phone_number' => MERCHANT_PHONE])
        ->assertJsonPath('data.available', false);

    expect(App\Models\MerchantAccount::where('phone_number', MERCHANT_PHONE)->count())->toBe(1);
});
