<?php

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(function () {
    (new PlanCatalogueSeeder)->run();

    Http::fake([
        'edahab.net/api/api/IssueInvoice*' => Http::response(fixture('edahab/check-invoice-pending.json')),
        'edahab.net/api/api/checkInvoiceStatus*' => Http::response(['InvoiceStatus' => 'Paid', 'TransactionId' => 'MP260926.0900.A00001', 'StatusCode' => 0]),
        'api.waafipay.net/*' => Http::response(['errorCode' => '0', 'params' => ['state' => 'approved', 'referenceId' => '111111', 'transactionId' => '42750126']]),
    ]);
});

const VERIFY_PHONE = '+252654880001';
const SAME_KEY = 'b4f1c8de-92a7-4f10-9c33-0a5e7d2b6f81';

/**
 * Pays for $purpose and polls until paid; returns the public invoice id.
 */
function payFor(string $purpose, string $phone, string $wallet, string $rail = 'edahab', string $key = SAME_KEY): string
{
    $quote = test()->getJson('/api/v1/registration/quote?purpose='.$purpose)->assertOk()->json('data.quote_id');

    $invoiceId = test()->postJson('/api/v1/registration/invoices', [
        'phone_number' => $phone, 'wallet_number' => $wallet, 'rail' => $rail,
        'purpose' => $purpose, 'quote_id' => $quote, 'idempotency_key' => $key,
    ])->assertStatus(202)->json('data.invoice_id');

    test()->getJson('/api/v1/registration/invoices/'.$invoiceId)->assertOk()->assertJsonPath('data.status', 'paid');

    return $invoiceId;
}

/**
 * Registers VERIFY_PHONE end to end and returns [merchant id, token].
 */
function registeredShop(): array
{
    $invoiceId = payFor('registration', VERIFY_PHONE, VERIFY_PHONE);

    $merchantId = test()->postJson('/api/v1/merchants', [
        'invoice_id' => $invoiceId, 'first_name' => 'Hodan', 'last_name' => 'Ali', 'dob' => '1992-02-02',
        'phone_number' => VERIFY_PHONE, 'business_name' => 'Hodan Store', 'state' => 'maroodi_jeex', 'city' => 'Hargeisa',
    ])->assertCreated()->json('data.merchant.id');

    $token = test()->postJson('/api/v1/auth/pin', ['phone_number' => VERIFY_PHONE, 'pin' => '2580', 'pin_confirmation' => '2580'], ['X-EXELO-Device-Id' => 'verify-till'])
        ->assertCreated()->json('data.token');

    return [$merchantId, $token];
}

it('verifies the registered number itself with a separate payment from that number', function () {
    [$merchantId, $token] = registeredShop();

    // Same phone, same wallet, even the same idempotency key as registration: still a new, separate payment.
    $verificationId = payFor('verification', VERIFY_PHONE, VERIFY_PHONE);

    expect(Invoice::where('public_id', $verificationId)->value('type'))->toBe('Verification')
        ->and(Invoice::where('mobile_number', VERIFY_PHONE)->pluck('type')->sort()->values()->all())->toBe(['Registration', 'Verification']);

    test()->withToken($token)->postJson("/api/v1/merchants/{$merchantId}/verification/complete", ['invoice_id' => $verificationId])
        ->assertOk()
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.wallets.edahab_number', ['number' => VERIFY_PHONE, 'status' => 'verified']);

    $shop = Merchant::find($merchantId);

    expect($shop->edahab_number)->toBe(VERIFY_PHONE)
        ->and($shop->wallet_states['edahab']['status'])->toBe('verified')
        ->and($shop->default_rail)->toBe('edahab');

    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/payments/methods')->assertOk()->assertJsonPath('data.accepts', ['edahab', 'cash']);
});

it('makes the paying wallet the payout number on its rail and leaves other pending numbers pending', function () {
    [$merchantId, $token] = registeredShop();

    test()->withToken($token)->patchJson('/api/v1/merchant/wallets', ['wallets' => [['rail' => 'edahab', 'number' => '+252654880009']]])
        ->assertOk()->assertJsonPath('data.verification_required', ['edahab']);

    $verificationId = payFor('verification', VERIFY_PHONE, '+252634880002', 'zaad', 'zaad-key');

    app('auth')->forgetGuards();
    test()->withToken($token)->postJson("/api/v1/merchants/{$merchantId}/verification/complete", ['invoice_id' => $verificationId])
        ->assertOk()
        ->assertJsonPath('data.wallets.zaad_number', ['number' => '+252634880002', 'status' => 'verified'])
        ->assertJsonPath('data.wallets.edahab_number', ['number' => '+252654880009', 'status' => 'pending']);
});

it('refuses a verification payment from a wallet that belongs to another shop, before charging', function () {
    registeredShop();

    $other = User::create(['name' => 'Other', 'email' => 'other-verify@example.test', 'password' => Hash::make('x'), 'user_type' => 'merchant']);
    Merchant::create([
        'user_id' => $other->id, 'first_name' => 'O', 'last_name' => 'T', 'phone_number' => '+252634880099',
        'business_name' => 'Other', 'state' => 'Sahil', 'city' => 'Berbera', 'location' => 'Berbera, Sahil',
        'is_approved' => true, 'edahab_number' => '+252654880077',
    ]);

    $quote = test()->getJson('/api/v1/registration/quote?purpose=verification')->json('data.quote_id');

    test()->postJson('/api/v1/registration/invoices', [
        'phone_number' => VERIFY_PHONE, 'wallet_number' => '+252654880077', 'rail' => 'edahab',
        'purpose' => 'verification', 'quote_id' => $quote, 'idempotency_key' => 'taken-wallet',
    ])->assertStatus(409)->assertJsonPath('error.code', 'wallet.number_taken');

    expect(Invoice::where('type', 'Verification')->where('wallet_number', '+252654880077')->exists())->toBeFalse();
});

it('verifies another shop\'s wallet without switching to that shop', function () {
    [$firstShopId, $token] = registeredShop();
    $first = App\Models\Shop::find($firstShopId);

    // A second shop of the same merchant, with its own number. The session stays on the first shop.
    $second = App\Models\Shop::create([
        'user_id' => $first->user_id, 'merchant_id' => $first->merchant_id, 'phone_number' => '+252654880002',
        'business_name' => 'Second Shop', 'state' => 'Sahil', 'city' => 'Berbera', 'location' => 'Berbera, Sahil', 'is_approved' => true,
    ]);

    // Its number pays the verification fee for itself.
    $invoiceId = payFor('verification', '+252654880002', '+252654880002', 'edahab', 'second-shop-key');

    test()->withToken($token)->postJson("/api/v1/merchants/{$second->id}/verification/complete", ['invoice_id' => $invoiceId])
        ->assertOk()
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.wallets.edahab_number', ['number' => '+252654880002', 'status' => 'verified']);

    expect($second->fresh()->edahab_number)->toBe('+252654880002')
        ->and($second->fresh()->wallet_states['edahab']['status'])->toBe('verified')
        ->and($first->fresh()->edahab_number)->toBeNull()
        ->and(App\Models\Invoice::where('public_id', $invoiceId)->value('consumed_at'))->not->toBeNull();

    // The same payment can't be used twice.
    app('auth')->forgetGuards();
    test()->withToken($token)->postJson("/api/v1/merchants/{$second->id}/verification/complete", ['invoice_id' => $invoiceId])
        ->assertStatus(409)->assertJsonPath('error.code', 'registration.invoice_consumed');
});

it('refuses to verify a shop that is not the merchant\'s, and says which shops are', function () {
    [$firstShopId, $token] = registeredShop();

    $stranger = App\Models\Shop::create(['phone_number' => '+252654880003', 'business_name' => 'Not yours', 'is_approved' => true]);

    test()->withToken($token)->postJson("/api/v1/merchants/{$stranger->id}/verification/complete")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'merchant.not_found')
        ->assertJsonPath('error.details.your_shop_ids', [$firstShopId]);
});

it('refuses a verification payment for a number with no account', function () {
    $quote = test()->getJson('/api/v1/registration/quote?purpose=verification')->json('data.quote_id');

    test()->postJson('/api/v1/registration/invoices', [
        'phone_number' => '+252654880055', 'wallet_number' => '+252654880055', 'rail' => 'edahab',
        'purpose' => 'verification', 'quote_id' => $quote, 'idempotency_key' => 'no-shop',
    ])->assertStatus(422)->assertJsonPath('error.details.phone_number.0', 'This number has no EXELO account');
});
