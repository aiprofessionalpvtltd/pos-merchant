<?php

use App\Models\Merchant;
use App\Services\MerchantProfileService;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

function profileAs(): array
{
    $owner = makeMerchant('2580');

    return [$owner, ownerToken()];
}

it('returns the shop profile to the owner', function () {
    [$owner, $token] = profileAs();

    test()->withToken($token)->getJson('/api/v1/merchant')
        ->assertOk()
        ->assertJsonPath('data.id', $owner->merchant->id)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.business_name', 'Exelo Retail')
        ->assertJsonPath('data.owner.short_name', 'KA')
        ->assertJsonPath('data.address.location', 'Hargeisa, Maroodi Jeex')
        ->assertJsonPath('data.logo', null)
        ->assertJsonPath('data.subscription.status', 'active')
        ->assertJsonPath('data.vat_rate', 0.05);
});

it('lets staff read but never write the shop', function () {
    $owner = makeMerchant('2580');
    makeStaff($owner, '+252634990201');
    $token = staffToken('+252634990201');

    test()->withToken($token)->getJson('/api/v1/merchant')->assertOk()->assertJsonPath('data.business_name', 'Exelo Retail');
    test()->withToken($token)->getJson('/api/v1/merchant/wallets')->assertOk();
    test()->withToken($token)->getJson('/api/v1/merchant/settings')->assertOk();

    test()->withToken($token)->patchJson('/api/v1/merchant', ['business_name' => 'Hijacked'])->assertStatus(403)->assertJsonPath('error.code', 'auth.merchant_only');
    test()->withToken($token)->patchJson('/api/v1/merchant/wallets', ['wallets' => [['rail' => 'evc', 'number' => '+252614440001']]])->assertStatus(403);
    test()->withToken($token)->patchJson('/api/v1/merchant/settings', ['exchange_rate' => 9000])->assertStatus(403);

    expect($owner->merchant->fresh()->business_name)->toBe('Exelo Retail');
});

it('requires a token', function () {
    test()->getJson('/api/v1/merchant')->assertStatus(401);
});

it('edits the profile, recomputes the location and bumps the version', function () {
    [$owner, $token] = profileAs();

    test()->withToken($token)->patchJson('/api/v1/merchant', [
        'business_name' => 'Exelo Retail & Wholesale', 'state' => 'togdheer', 'city' => 'Burao', 'merchant_code' => 'TST-PROFILE-1',
    ])->assertOk()
        ->assertJsonPath('message', 'Shop details saved')
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.address.state', 'Togdheer')
        ->assertJsonPath('data.address.state_code', 'togdheer')
        ->assertJsonPath('data.address.location', 'Burao, Togdheer');

    $merchant = $owner->merchant->fresh();
    expect($merchant->merchant_code)->toBe('TST-PROFILE-1')->and($merchant->version)->toBe(2);

    test()->withToken($token)->patchJson('/api/v1/merchant', ['first_name' => 'Kaalid'])->assertOk()->assertJsonPath('data.version', 3);
    expect($owner->fresh()->name)->toBe('Kaalid Ahmed');

    test()->withToken($token)->patchJson('/api/v1/merchant', [])->assertOk()->assertJsonPath('data.version', 3);
});

it('refuses a stale If-Match with the current copy', function () {
    [, $token] = profileAs();

    test()->withToken($token)->patchJson('/api/v1/merchant', ['city' => 'Berbera'])->assertOk();

    test()->withToken($token)->withHeader('If-Match', '1')->patchJson('/api/v1/merchant', ['city' => 'Borama'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'resource.version_conflict')
        ->assertJsonPath('error.details.current.version', 2)
        ->assertJsonPath('error.details.current.address.city', 'Berbera');

    test()->withToken($token)->withHeader('If-Match', '2')->patchJson('/api/v1/merchant', ['city' => 'Borama'])
        ->assertOk()
        ->assertJsonPath('data.version', 3);
});

it('validates the profile fields', function () {
    [$owner, $token] = profileAs();
    Merchant::create(['first_name' => 'Other', 'last_name' => 'Shop', 'phone_number' => '+252634990777', 'merchant_code' => 'TAKEN-1', 'other_merchant_code' => 'TAKEN-2']);

    test()->withToken($token)->patchJson('/api/v1/merchant', ['state' => 'nowhere', 'merchant_code' => 'TAKEN-1', 'other_merchant_code' => 'TAKEN-2'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.state.0', 'Choose a state')
        ->assertJsonPath('error.details.merchant_code.0', 'This code is already registered')
        ->assertJsonPath('error.details.other_merchant_code.0', 'This code is already registered');

    test()->withToken($token)->patchJson('/api/v1/merchant', ['merchant_code' => 'MINE-1'])->assertOk();
    test()->withToken($token)->patchJson('/api/v1/merchant', ['merchant_code' => 'MINE-1'])->assertOk();
});

it('lists all four wallets and treats legacy numbers as verified', function () {
    [$owner, $token] = profileAs();
    $owner->merchant->update(['zaad_number' => '+252632220001', 'edahab_number' => '252651110001']);

    $response = test()->withToken($token)->getJson('/api/v1/merchant/wallets')->assertOk();

    expect(collect($response->json('data.wallets'))->pluck('rail')->all())->toBe(['zaad', 'edahab', 'golis', 'evc']);
    $response->assertJsonPath('data.wallets.0.status', 'verified')
        ->assertJsonPath('data.wallets.0.is_default', true)
        ->assertJsonPath('data.wallets.1.number', '+252651110001')
        ->assertJsonPath('data.wallets.2.status', 'not_set')
        ->assertJsonPath('data.wallets.2.number', null)
        ->assertJsonPath('data.default_rail', 'zaad');
});

it('adds a wallet as pending, keeps unchanged numbers verified and removes with null', function () {
    [$owner, $token] = profileAs();
    $owner->merchant->update(['zaad_number' => '+252632220001']);

    test()->withToken($token)->patchJson('/api/v1/merchant/wallets', [
        'wallets' => [['rail' => 'zaad', 'number' => '0632220001'], ['rail' => 'evc', 'number' => '+252614440001']],
        'default_rail' => 'zaad',
    ])->assertOk()
        ->assertJsonPath('message', 'EVC number saved. Verify it to start receiving payments there.')
        ->assertJsonPath('data.verification_required', ['evc'])
        ->assertJsonPath('data.wallets.0.status', 'verified')
        ->assertJsonPath('data.wallets.3.status', 'pending')
        ->assertJsonPath('data.wallets.3.number', '+252614440001');

    $merchant = $owner->merchant->fresh();
    expect($merchant->version)->toBe(2);

    test()->withToken($token)->patchJson('/api/v1/merchant/wallets', ['wallets' => [['rail' => 'evc', 'number' => '+252614440002']]])
        ->assertOk()->assertJsonPath('data.verification_required', ['evc']);

    test()->withToken($token)->patchJson('/api/v1/merchant/wallets', ['wallets' => [['rail' => 'zaad', 'number' => null]]])
        ->assertOk()
        ->assertJsonPath('data.wallets.0.status', 'not_set')
        ->assertJsonPath('data.default_rail', 'evc');
});

it('refuses a number on the wrong rail, a number used elsewhere and a default that is not on file', function () {
    [$owner, $token] = profileAs();
    Merchant::create(['first_name' => 'Other', 'last_name' => 'Shop', 'phone_number' => '+252634990778', 'evc_number' => '+252614440009']);

    test()->withToken($token)->patchJson('/api/v1/merchant/wallets', ['wallets' => [['rail' => 'zaad', 'number' => '+252614440001']]])
        ->assertStatus(422)->assertJsonPath('error.code', 'payment.wallet_invalid')->assertJsonPath('error.field', 'wallets.0.number');

    test()->withToken($token)->patchJson('/api/v1/merchant/wallets', ['wallets' => [['rail' => 'evc', 'number' => '0614440009']]])
        ->assertStatus(409)->assertJsonPath('error.code', 'wallet.number_taken')->assertJsonPath('error.field', 'wallets.0.number');

    test()->withToken($token)->patchJson('/api/v1/merchant/wallets', ['wallets' => [['rail' => 'evc', 'number' => '+252614440001']], 'default_rail' => 'golis'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed')->assertJsonPath('error.field', 'default_rail');

    test()->withToken($token)->patchJson('/api/v1/merchant/wallets', ['wallets' => [['rail' => 'paypal', 'number' => '+252614440001']]])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    expect($owner->merchant->fresh()->evc_number)->toBeNull();
});

it('confirms pending wallets once verification is paid', function () {
    [$owner, $token] = profileAs();

    test()->withToken($token)->patchJson('/api/v1/merchant/wallets', ['wallets' => [['rail' => 'evc', 'number' => '+252614440001']]])->assertOk();

    app(MerchantProfileService::class)->markPendingWalletsVerified($owner->merchant->fresh());
    app('auth')->forgetGuards();

    test()->withToken($token)->getJson('/api/v1/merchant/wallets')
        ->assertJsonPath('data.wallets.3.status', 'verified')
        ->assertJsonPath('data.wallets.3.verified_at', fn ($value) => $value !== null);
});

it('returns default settings and merges nested changes', function () {
    [$owner, $token] = profileAs();

    test()->withToken($token)->getJson('/api/v1/merchant/settings')
        ->assertOk()
        ->assertJsonPath('data.vat_rate', 0.05)
        ->assertJsonPath('data.vat_label', '5%')
        ->assertJsonPath('data.exchange_rate', (int) config('exelo.conversion_rate'))
        ->assertJsonPath('data.exchange_rate_source', 'default')
        ->assertJsonPath('data.receipt.show_logo', true)
        ->assertJsonPath('data.alerts.default_stock_limit', 10)
        ->assertJsonPath('data.timezone', 'Africa/Mogadishu');

    test()->withToken($token)->patchJson('/api/v1/merchant/settings', [
        'exchange_rate' => 8500, 'receipt' => ['footer' => 'Mahadsanid!'], 'register' => ['allow_price_override' => false], 'vat_rate' => 0.025,
    ])->assertOk()
        ->assertJsonPath('message', 'Settings saved')
        ->assertJsonPath('data.exchange_rate', 8500)
        ->assertJsonPath('data.exchange_rate_source', 'manual')
        ->assertJsonPath('data.vat_label', '2.5%')
        ->assertJsonPath('data.receipt.footer', 'Mahadsanid!')
        ->assertJsonPath('data.receipt.show_logo', true)
        ->assertJsonPath('data.register.allow_price_override', false)
        ->assertJsonPath('data.register.scan_sound', true);

    test()->withToken($token)->patchJson('/api/v1/merchant/settings', ['receipt' => ['show_logo' => false]])->assertOk()
        ->assertJsonPath('data.receipt.footer', 'Mahadsanid!')
        ->assertJsonPath('data.receipt.show_logo', false);

    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/auth/session')->assertJsonPath('data.merchant.exchange_rate', 8500);
});

it('guards the exchange rate against typos', function () {
    [$owner, $token] = profileAs();

    test()->withToken($token)->patchJson('/api/v1/merchant/settings', ['exchange_rate' => 85000])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'settings.rate_out_of_range')
        ->assertJsonPath('error.field', 'exchange_rate')
        ->assertJsonPath('error.details.min', 5000)
        ->assertJsonPath('error.details.max', 15000);

    test()->withToken($token)->patchJson('/api/v1/merchant/settings', ['vat_rate' => 5, 'timezone' => 'Mars/Base'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    expect($owner->merchant->fresh()->exchange_rate)->toBeNull();
});
