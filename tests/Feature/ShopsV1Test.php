<?php

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

const OWNER_PHONE = '+252634770001';
const SECOND_SHOP_PHONE = '+252634770002';
const NEW_SHOP_PHONE = '+252634770003';
const STAFF_PHONE = '+252634770009';

function shopsOwner(): User
{
    $owner = User::create([
        'name' => 'Amina Yusuf', 'email' => 'amina-shops@example.test', 'password' => Hash::make('2580'),
        'user_type' => 'merchant', 'pin_set_at' => now(),
    ]);

    addShop($owner, OWNER_PHONE, 'Exelo Retail');

    return $owner;
}

function addShop(User $owner, string $phone, string $name): Merchant
{
    $shop = Merchant::create([
        'user_id' => $owner->id, 'first_name' => 'Amina', 'last_name' => 'Yusuf', 'dob' => '1990-01-01',
        'phone_number' => $phone, 'business_name' => $name, 'state' => 'Maroodi Jeex', 'state_code' => 'maroodi_jeex',
        'city' => 'Hargeisa', 'location' => 'Hargeisa, Maroodi Jeex', 'is_approved' => true,
    ]);

    MerchantSubscription::create([
        'merchant_id' => $shop->id, 'subscription_plan_id' => App\Models\SubscriptionPlan::default()->value('id'),
        'start_date' => now(), 'end_date' => null, 'transaction_status' => 'Paid',
    ]);

    return $shop;
}

function shopsLogin(string $phone = OWNER_PHONE, string $deviceId = 'till-1', array $extra = []): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/auth/pin/login', ['phone_number' => $phone, 'pin' => '2580'] + $extra, ['X-EXELO-Device-Id' => $deviceId]);
}

function shopsToken(string $phone = OWNER_PHONE, string $deviceId = 'till-1', array $extra = []): string
{
    return shopsLogin($phone, $deviceId, $extra)->assertOk()->json('data.token');
}

function paidShopInvoice(string $phone, string $status = 'Paid'): Invoice
{
    return Invoice::create([
        'public_id' => Invoice::generatePublicId(), 'invoice_id' => 'shop-'.$phone, 'transaction_id' => 'txn-'.$phone, 'hash' => '0',
        'mobile_number' => $phone, 'wallet_number' => '+252654770001', 'rail' => 'edahab',
        'amount' => 550, 'currency' => 'SLSH', 'status' => $status, 'type' => 'Registration',
    ]);
}

function newShopBody(Invoice $invoice, array $overrides = []): array
{
    return $overrides + [
        'invoice_id' => $invoice->public_id, 'business_name' => 'Berbera Mart', 'phone_number' => NEW_SHOP_PHONE,
        'state' => 'sahil', 'city' => 'Berbera',
    ];
}

function staffOf(Merchant $shop): User
{
    $user = User::create([
        'name' => 'Cashier', 'email' => 'cashier-shops@example.test', 'password' => Hash::make('2580'),
        'user_type' => 'employee', 'pin_set_at' => now(),
    ]);

    Employee::create([
        'user_id' => $user->id, 'merchant_id' => $shop->id, 'phone_number' => STAFF_PHONE,
        'first_name' => 'Cashier', 'last_name' => 'One', 'dob' => '1995-05-05', 'role' => 'Cashier', 'status' => 'active',
    ]);

    return $user;
}

function closeConfirmation(string $token): string
{
    return test()->withToken($token)->postJson('/api/v1/auth/pin/verify', ['pin' => '2580', 'scope' => 'shops.delete'])
        ->assertOk()->json('data.confirmation_token');
}

it('signs an owner in with every shop listed and the typed number\'s shop active', function () {
    $owner = shopsOwner();
    $second = addShop($owner, SECOND_SHOP_PHONE, 'Berbera Mart');

    $response = shopsLogin(SECOND_SHOP_PHONE)->assertOk()
        ->assertJsonPath('data.merchant.id', $second->id)
        ->assertJsonCount(2, 'data.shops');

    expect(collect($response->json('data.shops'))->pluck('is_active', 'business_name')->all())
        ->toBe(['Exelo Retail' => false, 'Berbera Mart' => true])
        ->and($response->json('data.shops.0.role'))->toBe('owner')
        ->and($response->json('data.shops.0.plan'))->toBe('silver');
});

it('signs straight into the shop asked for, and refuses a shop that is not theirs', function () {
    $owner = shopsOwner();
    $second = addShop($owner, SECOND_SHOP_PHONE, 'Berbera Mart');
    $stranger = addShop(User::create(['name' => 'X', 'email' => 'x-shops@example.test', 'password' => 'x', 'user_type' => 'merchant']), '+252634770099', 'Other');

    shopsLogin(OWNER_PHONE, 'till-1', ['shop_id' => $second->id])->assertOk()->assertJsonPath('data.merchant.id', $second->id);

    shopsLogin(OWNER_PHONE, 'till-1', ['shop_id' => $stranger->id])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'shop.not_a_member');
});

it('remembers the shop a device was last using', function () {
    $owner = shopsOwner();
    $second = addShop($owner, SECOND_SHOP_PHONE, 'Berbera Mart');

    $token = shopsToken();
    test()->withToken($token)->postJson("/api/v1/shops/{$second->id}/select")->assertOk();

    shopsLogin(OWNER_PHONE, 'till-1')->assertJsonPath('data.merchant.id', $second->id);
    shopsLogin(OWNER_PHONE, 'till-2')->assertJsonPath('data.merchant.id', $owner->ownedShops()->orderBy('id')->value('id'));
});

it('switches shop for this token only, without a PIN', function () {
    $owner = shopsOwner();
    $first = $owner->ownedShops()->first();
    $second = addShop($owner, SECOND_SHOP_PHONE, 'Berbera Mart');

    $tillOne = shopsToken(OWNER_PHONE, 'till-1');
    $tillTwo = shopsToken(OWNER_PHONE, 'till-2');

    test()->withToken($tillOne)->postJson("/api/v1/shops/{$second->id}/select")
        ->assertOk()
        ->assertJsonPath('message', 'Now working in Berbera Mart')
        ->assertJsonPath('data.merchant.id', $second->id)
        ->assertJsonStructure(['data' => ['user', 'merchant', 'subscription', 'permissions', 'shift', 'shops']]);

    // Everything this token does now acts for the new shop…
    app('auth')->forgetGuards();
    test()->withToken($tillOne)->getJson('/api/v1/merchant')->assertOk()->assertJsonPath('data.business_name', 'Berbera Mart');

    // …while the other till stays where it was.
    app('auth')->forgetGuards();
    test()->withToken($tillTwo)->getJson('/api/v1/merchant')->assertOk()->assertJsonPath('data.business_name', $first->business_name);
});

it('refuses to switch to a shop that is not theirs', function () {
    shopsOwner();
    $stranger = addShop(User::create(['name' => 'X', 'email' => 'x2-shops@example.test', 'password' => 'x', 'user_type' => 'merchant']), '+252634770098', 'Other');

    test()->withToken(shopsToken())->postJson("/api/v1/shops/{$stranger->id}/select")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'shop.not_found');
});

it('lists the shops with the active one marked', function () {
    $owner = shopsOwner();
    addShop($owner, SECOND_SHOP_PHONE, 'Berbera Mart');
    $token = shopsToken();

    test()->withToken($token)->getJson('/api/v1/shops')
        ->assertOk()
        ->assertJsonCount(2, 'data.shops')
        ->assertJsonPath('data.shops.0.is_active', true)
        ->assertJsonPath('data.active_shop_id', $owner->ownedShops()->orderBy('id')->value('id'));
});

it('opens another shop paid for by a registration invoice for its number', function () {
    $owner = shopsOwner();
    $invoice = paidShopInvoice(NEW_SHOP_PHONE);
    $token = shopsToken();

    $response = test()->withToken($token)->postJson('/api/v1/shops', newShopBody($invoice, ['merchant_code' => '740999']))
        ->assertCreated()
        ->assertJsonPath('data.shop.business_name', 'Berbera Mart')
        ->assertJsonPath('data.shop.role', 'owner')
        ->assertJsonPath('data.shop.is_active', false)
        ->assertJsonPath('data.subscription.plan', 'silver');

    $shop = Merchant::find($response->json('data.shop.id'));

    expect($shop->only(['user_id', 'first_name', 'last_name', 'phone_number', 'location', 'merchant_code']))->toBe([
        'user_id' => $owner->id, 'first_name' => 'Amina', 'last_name' => 'Yusuf', 'phone_number' => NEW_SHOP_PHONE,
        'location' => 'Berbera, Sahil', 'merchant_code' => '740999',
    ])->and((bool) $shop->is_approved)->toBeTrue()
        ->and($invoice->fresh()->consumed_at)->not->toBeNull()
        ->and(MerchantSubscription::where('merchant_id', $shop->id)->exists())->toBeTrue();

    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/shops')->assertJsonCount(2, 'data.shops');
});

it('refuses to open a shop without a usable payment or with a taken number', function () {
    shopsOwner();
    $token = shopsToken();

    test()->withToken($token)->postJson('/api/v1/shops', newShopBody(paidShopInvoice(NEW_SHOP_PHONE, 'Pending')))
        ->assertStatus(402)->assertJsonPath('error.code', 'registration.invoice_unpaid');

    $paid = paidShopInvoice('+252634770004');
    test()->withToken($token)->postJson('/api/v1/shops', newShopBody($paid))
        ->assertStatus(422)->assertJsonPath('error.details.phone_number.0', 'This number does not match the payment');

    test()->withToken($token)->postJson('/api/v1/shops', newShopBody(paidShopInvoice(OWNER_PHONE), ['phone_number' => OWNER_PHONE]))
        ->assertStatus(409)->assertJsonPath('error.code', 'registration.phone_taken');

    $used = paidShopInvoice('+252634770005');
    test()->withToken($token)->postJson('/api/v1/shops', newShopBody($used, ['phone_number' => '+252634770005']))->assertCreated();
    test()->withToken($token)->postJson('/api/v1/shops', newShopBody($used, ['phone_number' => '+252634770005']))
        ->assertStatus(409)->assertJsonPath('error.code', 'registration.invoice_consumed');

    test()->withToken($token)->postJson('/api/v1/shops', [])->assertStatus(422);
});

it('shows and edits a shop that is not the active one', function () {
    $owner = shopsOwner();
    $second = addShop($owner, SECOND_SHOP_PHONE, 'Berbera Mart');
    $token = shopsToken();

    test()->withToken($token)->getJson("/api/v1/shops/{$second->id}")
        ->assertOk()->assertJsonPath('data.business_name', 'Berbera Mart');

    test()->withToken($token)->patchJson("/api/v1/shops/{$second->id}", ['business_name' => 'Berbera Mart & Pharmacy', 'state' => 'sahil', 'city' => 'Berbera'])
        ->assertOk()->assertJsonPath('data.business_name', 'Berbera Mart & Pharmacy');

    expect($second->fresh()->location)->toBe('Berbera, Sahil')
        ->and($owner->ownedShops()->orderBy('id')->first()->business_name)->toBe('Exelo Retail');
});

it('keeps staff to their own shop and away from shop management', function () {
    $owner = shopsOwner();
    $first = $owner->ownedShops()->first();
    $second = addShop($owner, SECOND_SHOP_PHONE, 'Berbera Mart');
    staffOf($first);

    $token = shopsToken(STAFF_PHONE);

    test()->withToken($token)->getJson('/api/v1/shops')
        ->assertOk()->assertJsonCount(1, 'data.shops')->assertJsonPath('data.shops.0.role', 'staff');

    test()->withToken($token)->postJson("/api/v1/shops/{$second->id}/select")->assertNotFound();
    test()->withToken($token)->postJson('/api/v1/shops', newShopBody(paidShopInvoice(NEW_SHOP_PHONE)))->assertForbidden();
    test()->withToken($token)->patchJson("/api/v1/shops/{$first->id}", ['business_name' => 'Mine now'])->assertForbidden();
    test()->withToken($token)->deleteJson("/api/v1/shops/{$first->id}")->assertForbidden();
});

it('closes a shop: staff removed, tokens moved to another shop, hidden from the list', function () {
    $owner = shopsOwner();
    $first = $owner->ownedShops()->first();
    $second = addShop($owner, SECOND_SHOP_PHONE, 'Berbera Mart');
    $staff = staffOf($second);
    $staffToken = shopsToken(STAFF_PHONE, 'till-9');

    $token = shopsToken(OWNER_PHONE, 'till-1', ['shop_id' => $second->id]);
    $otherDevice = shopsToken(OWNER_PHONE, 'till-2', ['shop_id' => $second->id]);

    test()->withToken($token)->deleteJson("/api/v1/shops/{$second->id}")
        ->assertStatus(401)->assertJsonPath('error.code', 'auth.confirmation_required');

    test()->withToken($token)->withHeader('X-EXELO-Confirmation', closeConfirmation($token))
        ->deleteJson("/api/v1/shops/{$second->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.staff_removed', 1)
        ->assertJsonPath('data.active_shop_id', $first->id);

    expect(Merchant::find($second->id))->toBeNull()
        ->and(Merchant::withTrashed()->find($second->id)->trashed())->toBeTrue()
        ->and(Employee::where('user_id', $staff->id)->value('status'))->toBe('inactive')
        ->and(PersonalAccessToken::where('merchant_id', $second->id)->exists())->toBeFalse();

    app('auth')->forgetGuards();
    test()->withToken($otherDevice)->getJson('/api/v1/merchant')->assertOk()->assertJsonPath('data.business_name', 'Exelo Retail');

    app('auth')->forgetGuards();
    test()->withToken($staffToken)->getJson('/api/v1/auth/session')->assertUnauthorized();

    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/shops')->assertJsonCount(1, 'data.shops');
});

it('refuses to close the only shop or one with a payment still waiting', function () {
    $owner = shopsOwner();
    $first = $owner->ownedShops()->first();
    $token = shopsToken();

    test()->withToken($token)->withHeader('X-EXELO-Confirmation', closeConfirmation($token))
        ->deleteJson("/api/v1/shops/{$first->id}")
        ->assertStatus(409)->assertJsonPath('error.code', 'shop.last_shop');

    $second = addShop($owner, SECOND_SHOP_PHONE, 'Berbera Mart');
    app('auth')->forgetGuards();
    Invoice::create([
        'public_id' => Invoice::generatePublicId(), 'merchant_id' => $second->id, 'invoice_id' => 'pending-1', 'transaction_id' => 'p1', 'hash' => '0',
        'mobile_number' => '+252654770001', 'amount' => 92000, 'currency' => 'SLSH', 'status' => 'Pending', 'type' => 'Subscription',
        'rail' => 'edahab', 'expires_at' => now()->addMinutes(5),
    ]);

    test()->withToken($token)->withHeader('X-EXELO-Confirmation', closeConfirmation($token))
        ->deleteJson("/api/v1/shops/{$second->id}")
        ->assertStatus(409)->assertJsonPath('error.code', 'shop.payments_pending');

    expect($second->fresh())->not->toBeNull();
});

it('keeps tokens issued before multi-shop working on the owner\'s first shop', function () {
    $owner = shopsOwner();
    addShop($owner, SECOND_SHOP_PHONE, 'Berbera Mart');
    $legacyToken = $owner->createToken('device:old-till')->plainTextToken;

    test()->withToken($legacyToken)->getJson('/api/v1/merchant')->assertOk()->assertJsonPath('data.business_name', 'Exelo Retail');
});
