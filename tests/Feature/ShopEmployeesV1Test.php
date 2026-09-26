<?php

use App\Models\Employee;
use App\Models\EmployeePermission;
use App\Models\MerchantAccount;
use App\Models\MerchantSubscription;
use App\Models\POSPermission;
use App\Models\Shift;
use App\Models\Shop;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

const TEAM_OWNER_PHONE = '+252654660001';
const TEAM_DEVICE = ['X-EXELO-Device-Id' => 'team-till'];

/**
 * A merchant (merchants row) with three shops: two on Gold, one on the free plan.
 *
 * @return array{0: User, 1: Shop, 2: Shop, 3: Shop}
 */
function teamOwner(): array
{
    $user = User::create(['name' => 'Hodan Ali', 'email' => 'team-owner@example.test', 'password' => Hash::make('2580'), 'user_type' => 'merchant', 'pin_set_at' => now()]);
    $account = MerchantAccount::create(['user_id' => $user->id, 'first_name' => 'Hodan', 'last_name' => 'Ali', 'phone_number' => TEAM_OWNER_PHONE, 'phone_verified_at' => now()]);

    $shops = [];
    foreach ([['+252634660011', 'Hodan Main', 'gold'], ['+252634660012', 'Hodan Berbera', 'gold'], ['+252634660013', 'Hodan Kiosk', 'silver']] as [$phone, $name, $plan]) {
        $shop = Shop::create([
            'user_id' => $user->id, 'merchant_id' => $account->id, 'phone_number' => $phone, 'business_name' => $name,
            'state' => 'Maroodi Jeex', 'state_code' => 'maroodi_jeex', 'city' => 'Hargeisa', 'location' => 'Hargeisa, Maroodi Jeex', 'is_approved' => true,
        ]);

        MerchantSubscription::create([
            'merchant_id' => $shop->id, 'subscription_plan_id' => SubscriptionPlan::where('key', $plan)->value('id'),
            'start_date' => now()->subDay()->toDateString(), 'end_date' => $plan === 'gold' ? now()->addMonth()->toDateString() : null,
            'transaction_status' => 'Paid',
        ]);

        $shops[] = $shop;
    }

    return [$user, ...$shops];
}

/**
 * @param  array<int, string>  $keys
 */
function teamMember(Shop $shop, string $phone, array $keys = ['pos'], string $firstName = 'Nasra'): Employee
{
    $user = User::create(['name' => $firstName, 'email' => "team{$phone}@example.test", 'password' => Hash::make('2580'), 'user_type' => 'employee', 'pin_set_at' => now()]);

    $employee = Employee::create([
        'user_id' => $user->id, 'shop_id' => $shop->id, 'phone_number' => $phone, 'first_name' => $firstName, 'last_name' => 'Yusuf',
        'dob' => '1998-04-12', 'role' => 'Cashier', 'status' => 'active',
    ]);

    foreach ($keys as $key) {
        // permission_key is computed, not a column.
        $permissionId = POSPermission::all()->first(fn (POSPermission $permission) => $permission->permission_key === $key)->id;
        EmployeePermission::create(['employee_id' => $employee->id, 'pos_permission_id' => $permissionId]);
    }

    return $employee;
}

function teamLogin(string $phone = TEAM_OWNER_PHONE, array $extra = []): string
{
    return test()->postJson('/api/v1/auth/pin/login', ['phone_number' => $phone, 'pin' => '2580'] + $extra, TEAM_DEVICE)->assertOk()->json('data.token');
}

function teamConfirmation(string $token): string
{
    return test()->withToken($token)->postJson('/api/v1/auth/pin/verify', ['pin' => '2580', 'scope' => 'employees.create'])->json('data.confirmation_token');
}

function teamNewStaff(array $overrides = []): array
{
    return $overrides + [
        'first_name' => 'Ayaan', 'last_name' => 'Warsame', 'phone_number' => '+252634660099', 'dob' => '2000-01-19',
        'role' => 'Cashier', 'permission_keys' => ['pos'],
    ];
}

it('adds staff to another of the owner\'s shops without switching', function () {
    [, $main, $berbera] = teamOwner();
    $token = teamLogin();

    test()->withToken($token)->withHeader('X-EXELO-Confirmation', teamConfirmation($token))
        ->postJson('/api/v1/employees', teamNewStaff(['shop_id' => $berbera->id]))
        ->assertCreated()
        ->assertJsonPath('data.shop', ['id' => $berbera->id, 'business_name' => 'Hodan Berbera']);

    expect(Employee::where('phone_number', '+252634660099')->value('shop_id'))->toBe($berbera->id);

    // The session still works on the current shop, which has no staff yet.
    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/employees')->assertOk()->assertJsonCount(0, 'data');

    // Without shop_id, staff join the current shop, as before.
    app('auth')->forgetGuards();
    test()->withToken($token)->withHeader('X-EXELO-Confirmation', teamConfirmation($token))
        ->postJson('/api/v1/employees', teamNewStaff(['phone_number' => '+252634660098']))
        ->assertCreated()
        ->assertJsonPath('data.shop.id', $main->id);
});

it('refuses a shop that is not the owner\'s, or whose plan has no staff', function () {
    [, , , $kiosk] = teamOwner();
    $token = teamLogin();
    $stranger = Shop::create(['phone_number' => '+252634660077', 'business_name' => 'Other', 'is_approved' => true]);

    test()->withToken($token)->withHeader('X-EXELO-Confirmation', teamConfirmation($token))
        ->postJson('/api/v1/employees', teamNewStaff(['shop_id' => $stranger->id]))
        ->assertNotFound()->assertJsonPath('error.code', 'shop.not_found');

    app('auth')->forgetGuards();
    test()->withToken($token)->withHeader('X-EXELO-Confirmation', teamConfirmation($token))
        ->postJson('/api/v1/employees', teamNewStaff(['shop_id' => $kiosk->id]))
        ->assertForbidden()->assertJsonPath('error.code', 'plan.feature_unavailable');
});

it('keeps a staff manager to their own shop', function () {
    [, $main, $berbera] = teamOwner();
    teamMember($main, '+252634660021', ['employees'], 'Manager');
    $token = teamLogin('+252634660021');

    test()->withToken($token)->postJson('/api/v1/employees', teamNewStaff(['shop_id' => $berbera->id]))
        ->assertForbidden()->assertJsonPath('error.code', 'auth.merchant_only');

    test()->withToken($token)->getJson('/api/v1/account/employees')->assertForbidden();
    test()->withToken($token)->postJson('/api/v1/employees/1/transfer', ['shop_id' => $berbera->id])->assertForbidden();
});

it('lists the staff of every shop with their shop and per-shop counts', function () {
    [, $main, $berbera, $kiosk] = teamOwner();
    teamMember($main, '+252634660031', ['pos'], 'Amina');
    teamMember($main, '+252634660032', ['pos'], 'Bilan');
    teamMember($berbera, '+252634660033', ['pos'], 'Caasha');
    $gone = teamMember($berbera, '+252634660034', ['pos'], 'Deqa');
    $gone->update(['status' => 'inactive']);
    $token = teamLogin();

    test()->withToken($token)->getJson('/api/v1/account/employees')
        ->assertOk()
        ->assertJsonCount(3, 'data.employees')
        ->assertJsonPath('data.employees.0.first_name', 'Amina')
        ->assertJsonPath('data.employees.0.shop.business_name', 'Hodan Main')
        ->assertJsonPath('data.employees.2.shop.id', $berbera->id)
        ->assertJsonPath('data.by_shop', [
            ['shop_id' => $main->id, 'business_name' => 'Hodan Main', 'active' => 2],
            ['shop_id' => $berbera->id, 'business_name' => 'Hodan Berbera', 'active' => 1],
            ['shop_id' => $kiosk->id, 'business_name' => 'Hodan Kiosk', 'active' => 0],
        ])
        ->assertJsonPath('meta.pagination.total', 3);

    test()->withToken($token)->getJson('/api/v1/account/employees?shop_id='.$berbera->id)
        ->assertJsonCount(1, 'data.employees')->assertJsonPath('data.employees.0.first_name', 'Caasha');

    test()->withToken($token)->getJson('/api/v1/account/employees?status=inactive')
        ->assertJsonCount(1, 'data.employees')->assertJsonPath('data.employees.0.first_name', 'Deqa');

    test()->withToken($token)->getJson('/api/v1/account/employees?shop_id=999999')->assertNotFound();
});

it('moves staff to another shop and ends their sessions', function () {
    [, $main, $berbera] = teamOwner();
    $employee = teamMember($main, '+252634660041');
    $staffToken = teamLogin('+252634660041');
    $token = teamLogin();

    test()->withToken($token)->postJson("/api/v1/employees/{$employee->id}/transfer", ['shop_id' => $berbera->id])
        ->assertOk()
        ->assertJsonPath('data.shop.id', $berbera->id)
        ->assertJsonPath('message', 'Nasra now works in Hodan Berbera. They need to sign in again.')
        ->assertJsonPath('data.permissions.0.key', 'pos');

    expect($employee->fresh()->shop_id)->toBe($berbera->id);

    app('auth')->forgetGuards();
    test()->withToken($staffToken)->getJson('/api/v1/auth/session')->assertUnauthorized();

    // Signing in again lands in the new shop.
    app('auth')->forgetGuards();
    test()->postJson('/api/v1/auth/pin/login', ['phone_number' => '+252634660041', 'pin' => '2580'], TEAM_DEVICE)
        ->assertOk()->assertJsonPath('data.merchant.id', $berbera->id);
});

it('refuses a transfer that cannot happen', function () {
    [, $main, $berbera, $kiosk] = teamOwner();
    $employee = teamMember($main, '+252634660051');
    $token = teamLogin();

    test()->withToken($token)->postJson("/api/v1/employees/{$employee->id}/transfer", ['shop_id' => $main->id])
        ->assertStatus(409)->assertJsonPath('error.code', 'employee.already_in_shop');

    test()->withToken($token)->postJson("/api/v1/employees/{$employee->id}/transfer", ['shop_id' => $kiosk->id])
        ->assertForbidden()->assertJsonPath('error.code', 'plan.feature_unavailable');

    test()->withToken($token)->postJson('/api/v1/employees/999999/transfer', ['shop_id' => $berbera->id])
        ->assertNotFound()->assertJsonPath('error.code', 'employee.not_found');

    Shift::create(['user_id' => $employee->user_id, 'start_time' => now()->subHour()]);

    test()->withToken($token)->postJson("/api/v1/employees/{$employee->id}/transfer", ['shop_id' => $berbera->id])
        ->assertStatus(409)->assertJsonPath('error.code', 'employee.shift_open');

    expect($employee->fresh()->shop_id)->toBe($main->id);
});

it('shows each shop\'s staff on the merchant account', function () {
    [, $main, $berbera] = teamOwner();
    teamMember($main, '+252634660061', ['pos'], 'Amina');
    teamMember($berbera, '+252634660062', ['pos'], 'Bilan');
    teamMember($berbera, '+252634660063', ['pos'], 'Caasha');

    test()->withToken(teamLogin())->getJson('/api/v1/account')
        ->assertOk()
        ->assertJsonPath('data.shops.0.staff_count', 1)
        ->assertJsonPath('data.shops.0.staff.0.first_name', 'Amina')
        ->assertJsonPath('data.shops.1.staff_count', 2)
        ->assertJsonPath('data.shops.1.staff.1.first_name', 'Caasha')
        ->assertJsonPath('data.shops.2.staff', []);

    expect(MerchantAccount::where('phone_number', TEAM_OWNER_PHONE)->first()->employees()->count())->toBe(3);
});

it('keeps GET /merchant exactly as it was without an include', function () {
    teamOwner();

    $data = test()->withToken(teamLogin())->getJson('/api/v1/merchant')->assertOk()
        ->assertJsonPath('data.business_name', 'Hodan Main')
        ->json('data');

    expect($data)->not->toHaveKeys(['merchant', 'shops', 'employees'])
        ->and($data['subscription'])->toBe(['plan_id' => SubscriptionPlan::where('key', 'gold')->value('id'), 'plan' => 'gold', 'status' => 'active']);
});

it('returns the merchant, every shop, the subscription and all staff with include=all', function () {
    [, $main, $berbera] = teamOwner();
    teamMember($main, '+252634660071', ['pos'], 'Amina');
    teamMember($berbera, '+252634660072', ['inventory'], 'Bilan');

    $data = test()->withToken(teamLogin())->getJson('/api/v1/merchant?include=all')->assertOk()->json('data');

    // Still the current shop's profile…
    expect($data['id'])->toBe($main->id)
        ->and($data['business_name'])->toBe('Hodan Main')
        ->and($data['owner'])->toHaveKey('phone_number')
        // …plus the merchant (own number), all three shops, the full subscription, and all staff.
        ->and($data['merchant']['phone_number'])->toBe(TEAM_OWNER_PHONE)
        ->and($data['merchant']['phone_verified'])->toBeTrue()
        ->and(collect($data['shops'])->pluck('business_name')->all())->toBe(['Hodan Main', 'Hodan Berbera', 'Hodan Kiosk'])
        ->and($data['shops'][0]['is_active'])->toBeTrue()
        ->and($data['shops'][0]['staff_count'])->toBe(1)
        ->and($data['shops'][0]['payout_wallets'])->toHaveCount(4)
        ->and($data['subscription']['plan'])->toBe('gold')
        ->and($data['subscription']['features'])->toContain('employees.manage')
        ->and($data['subscription'])->toHaveKeys(['plan_id', 'status', 'expires_at', 'started_at', 'can_upgrade'])
        // Each shop carries its own full subscription.
        ->and($data['shops'][2]['subscription']['plan'])->toBe('silver')
        ->and($data['shops'][2]['subscription']['features'])->not->toContain('employees.manage')
        ->and($data['employees']['total'])->toBe(2)
        ->and($data['employees']['has_more'])->toBeFalse()
        ->and(collect($data['employees']['items'])->map(fn ($employee) => $employee['first_name'].' @ '.$employee['shop']['business_name'])->all())
        ->toBe(['Amina @ Hodan Main', 'Bilan @ Hodan Berbera'])
        ->and(collect($data['employees']['by_shop'])->pluck('active', 'business_name')->all())
        ->toBe(['Hodan Main' => 1, 'Hodan Berbera' => 1, 'Hodan Kiosk' => 0]);
});

it('returns only the parts asked for', function () {
    teamOwner();

    $data = test()->withToken(teamLogin())->getJson('/api/v1/merchant?include=shops,employees')->assertOk()->json('data');

    expect($data)->toHaveKeys(['shops', 'employees', 'business_name'])
        ->and($data)->not->toHaveKey('merchant')
        ->and($data['subscription'])->not->toHaveKey('features')
        ->and($data['shops'][0]['subscription'])->not->toHaveKey('features');

    // The array form works too.
    app('auth')->forgetGuards();
    test()->withToken(teamLogin())->getJson('/api/v1/merchant?include[]=merchant')->assertOk()
        ->assertJsonPath('data.merchant.first_name', 'Hodan')
        ->assertJsonMissingPath('data.shops');
});

it('follows the shop the session is working in, with all shops still listed', function () {
    [, , $berbera] = teamOwner();
    $token = teamLogin();

    test()->withToken($token)->postJson("/api/v1/shops/{$berbera->id}/select")->assertOk();

    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/merchant?include=shops,subscription')->assertOk()
        ->assertJsonPath('data.id', $berbera->id)
        ->assertJsonCount(3, 'data.shops')
        ->assertJsonPath('data.shops.1.is_active', true)
        ->assertJsonPath('data.subscription.plan', 'gold');
});

it('lists the payout wallets of every shop, keeping the current shop\'s at the top', function () {
    [, $main, $berbera, $kiosk] = teamOwner();

    $verifiedAt = now()->toIso8601String();
    $main->forceFill(['edahab_number' => '+252654660501', 'wallet_states' => ['edahab' => ['status' => 'verified', 'verified_at' => $verifiedAt, 'rejection_reason' => null]], 'default_rail' => 'edahab'])->save();
    $berbera->forceFill(['zaad_number' => '+252634660502', 'evc_number' => '+252614660503', 'wallet_states' => [
        'zaad' => ['status' => 'verified', 'verified_at' => $verifiedAt, 'rejection_reason' => null],
        'evc' => ['status' => 'pending', 'verified_at' => null, 'rejection_reason' => null],
    ]])->save();

    $token = teamLogin();

    $data = test()->withToken($token)->getJson('/api/v1/merchant/wallets')->assertOk()
        // Unchanged: the current shop's own wallets.
        ->assertJsonPath('data.default_rail', 'edahab')
        ->assertJsonPath('data.wallets.1.number', '+252654660501')
        ->assertJsonPath('data.active_shop_id', $main->id)
        ->assertJsonCount(3, 'data.shops')
        ->json('data.shops');

    expect(collect($data)->pluck('business_name')->all())->toBe(['Hodan Main', 'Hodan Berbera', 'Hodan Kiosk'])
        ->and($data[0]['is_active'])->toBeTrue()
        ->and($data[0]['verified_rails'])->toBe(['edahab'])
        ->and($data[0]['pending_rails'])->toBe([])
        ->and($data[1]['is_active'])->toBeFalse()
        ->and($data[1]['verified_rails'])->toBe(['zaad'])
        ->and($data[1]['pending_rails'])->toBe(['evc'])
        ->and($data[1]['default_rail'])->toBe('zaad')
        ->and(collect($data[1]['wallets'])->pluck('status', 'rail')->all())->toBe(['zaad' => 'verified', 'edahab' => 'not_set', 'golis' => 'not_set', 'evc' => 'pending'])
        ->and($data[2]['wallets'])->toHaveCount(4)
        ->and($data[2]['default_rail'])->toBeNull()
        ->and($data[2]['verified_rails'])->toBe([]);

    // Working in Berbera: the top level follows it, every shop is still listed.
    test()->withToken($token)->postJson("/api/v1/shops/{$berbera->id}/select")->assertOk();

    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/merchant/wallets')->assertOk()
        ->assertJsonPath('data.default_rail', 'zaad')
        ->assertJsonPath('data.active_shop_id', $berbera->id)
        ->assertJsonPath('data.shops.1.is_active', true)
        ->assertJsonCount(3, 'data.shops');
});

it('shows staff only the wallets of the shops they work in', function () {
    [, $main, $berbera] = teamOwner();
    $main->forceFill(['edahab_number' => '+252654660511', 'wallet_states' => ['edahab' => ['status' => 'verified', 'verified_at' => now()->toIso8601String(), 'rejection_reason' => null]]])->save();
    $berbera->forceFill(['zaad_number' => '+252634660512'])->save();
    teamMember($main, '+252634660513', ['pos'], 'Staffer');

    test()->withToken(teamLogin('+252634660513'))->getJson('/api/v1/merchant/wallets')->assertOk()
        ->assertJsonCount(1, 'data.shops')
        ->assertJsonPath('data.shops.0.business_name', 'Hodan Main')
        ->assertJsonPath('data.shops.0.verified_rails', ['edahab']);
});

it('reads the settings of one specific shop without switching to it', function () {
    [, $main, $berbera] = teamOwner();
    $berbera->forceFill(['vat_rate' => 0.10, 'exchange_rate' => 9000, 'timezone' => 'Africa/Nairobi'])->save();
    $token = teamLogin();

    // The session is on the main shop; the settings asked for are Berbera's own.
    test()->withToken($token)->getJson("/api/v1/shops/{$berbera->id}/settings")->assertOk()
        ->assertJsonPath('data.shop', ['id' => $berbera->id, 'business_name' => 'Hodan Berbera'])
        ->assertJsonPath('data.vat_label', '10%')
        ->assertJsonPath('data.exchange_rate', 9000)
        ->assertJsonPath('data.exchange_rate_source', 'manual')
        ->assertJsonPath('data.timezone', 'Africa/Nairobi')
        ->assertJsonPath('data.receipt.show_logo', true);

    // The shortcut still means the shop the session is working in.
    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/merchant/settings')->assertOk()
        ->assertJsonPath('data.vat_label', '5%')
        ->assertJsonPath('data.exchange_rate_source', 'default');
});

it('changes the settings of one shop and leaves the others alone', function () {
    [, $main, $berbera] = teamOwner();
    $token = teamLogin();
    $before = [$main->fresh()->version, $berbera->fresh()->version];

    test()->withToken($token)->patchJson("/api/v1/shops/{$berbera->id}/settings", [
        'vat_rate' => 0.07, 'vat_inclusive' => true, 'exchange_rate' => 8200, 'language' => 'so',
        'receipt' => ['footer' => 'Thanks for shopping'], 'alerts' => ['default_alarm_limit' => 6],
    ])->assertOk()
        ->assertJsonPath('message', 'Settings saved')
        ->assertJsonPath('data.shop.id', $berbera->id)
        ->assertJsonPath('data.vat_label', '7%')
        ->assertJsonPath('data.vat_inclusive', true)
        ->assertJsonPath('data.exchange_rate', 8200)
        ->assertJsonPath('data.receipt.footer', 'Thanks for shopping')
        ->assertJsonPath('data.receipt.show_logo', true)
        ->assertJsonPath('data.alerts', ['default_alarm_limit' => 6, 'default_stock_limit' => 10]);

    $berbera = $berbera->fresh();
    $main = $main->fresh();

    expect((float) $berbera->vat_rate)->toBe(0.07)
        ->and($berbera->exchange_rate)->toBe(8200)
        ->and($berbera->language)->toBe('so')
        ->and($berbera->version)->toBe($before[1] + 1)
        // Main shop untouched: same version, no manual rate, default VAT.
        ->and($main->version)->toBe($before[0])
        ->and($main->exchange_rate)->toBeNull()
        ->and((float) $main->vat_rate)->toBe(0.05);
});

it('stores the full receipt, register and alert preferences on every shop', function () {
    [, $main, $berbera] = teamOwner();
    $defaults = config('exelo.preference_defaults');

    // A new shop gets the whole set in its own row, not just what it later changes.
    expect($main->fresh()->preferences)->toEqual($defaults)
        ->and($berbera->fresh()->preferences)->toEqual($defaults);

    // Changing one value keeps all the others stored.
    test()->withToken(teamLogin())->patchJson("/api/v1/shops/{$berbera->id}/settings", ['receipt' => ['footer' => 'Thanks'], 'alerts' => ['default_alarm_limit' => 9]])->assertOk();

    $expected = $defaults;
    $expected['receipt']['footer'] = 'Thanks';
    $expected['alerts']['default_alarm_limit'] = 9;

    expect($berbera->fresh()->preferences)->toEqual($expected)
        ->and($main->fresh()->preferences)->toEqual($defaults);
});

it('still shows the defaults for a shop that has no stored preferences yet', function () {
    [, $main] = teamOwner();
    DB::table('shops')->where('id', $main->id)->update(['preferences' => null]);

    test()->withToken(teamLogin())->getJson("/api/v1/shops/{$main->id}/settings")->assertOk()
        ->assertJsonPath('data.receipt', config('exelo.preference_defaults.receipt'))
        ->assertJsonPath('data.register', config('exelo.preference_defaults.register'))
        ->assertJsonPath('data.alerts', config('exelo.preference_defaults.alerts'));
});

it('validates shop settings and refuses shops that are not the merchant\'s', function () {
    [, $main] = teamOwner();
    $stranger = Shop::create(['phone_number' => '+252634660091', 'business_name' => 'Not yours', 'is_approved' => true]);
    $token = teamLogin();

    test()->withToken($token)->patchJson("/api/v1/shops/{$main->id}/settings", ['vat_rate' => 2])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    test()->withToken($token)->patchJson("/api/v1/shops/{$main->id}/settings", ['exchange_rate' => 999999])
        ->assertStatus(422)->assertJsonPath('error.code', 'settings.rate_out_of_range');

    test()->withToken($token)->getJson("/api/v1/shops/{$stranger->id}/settings")
        ->assertNotFound()->assertJsonPath('error.code', 'shop.not_found');

    test()->withToken($token)->patchJson("/api/v1/shops/{$stranger->id}/settings", ['vat_rate' => 0.01])
        ->assertNotFound()->assertJsonPath('error.code', 'shop.not_found');

    expect($stranger->fresh()->vat_rate)->not->toBe(0.01);
});

it('lets staff read their own shop\'s settings but never change them', function () {
    [, $main, $berbera] = teamOwner();
    teamMember($main, '+252634660095', ['pos'], 'Staffer');
    $token = teamLogin('+252634660095');

    test()->withToken($token)->getJson("/api/v1/shops/{$main->id}/settings")->assertOk()
        ->assertJsonPath('data.shop.business_name', 'Hodan Main');

    app('auth')->forgetGuards();
    test()->withToken($token)->getJson("/api/v1/shops/{$berbera->id}/settings")->assertNotFound();

    app('auth')->forgetGuards();
    test()->withToken($token)->patchJson("/api/v1/shops/{$main->id}/settings", ['vat_rate' => 0.01])
        ->assertForbidden()->assertJsonPath('error.code', 'auth.merchant_only');
});

it('refuses an unknown include and keeps the extras from staff', function () {
    [, $main] = teamOwner();
    teamMember($main, '+252634660081', ['pos'], 'Staffer');
    $owner = teamLogin();

    test()->withToken($owner)->getJson('/api/v1/merchant?include=invoices')->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');

    // Staff still get the plain profile, but not the merchant-wide extras.
    $staff = teamLogin('+252634660081');

    app('auth')->forgetGuards();
    test()->withToken($staff)->getJson('/api/v1/merchant')->assertOk()->assertJsonPath('data.business_name', 'Hodan Main');

    app('auth')->forgetGuards();
    test()->withToken($staff)->getJson('/api/v1/merchant?include=all')->assertForbidden()
        ->assertJsonPath('error.code', 'auth.merchant_only');
});

it('stores both the shop and its merchant on every employee', function () {
    [$owner, $main, $berbera] = teamOwner();
    $employee = teamMember($berbera, '+252634660123');

    expect($employee->shop_id)->toBe($berbera->id)
        ->and($employee->merchant_id)->toBe($berbera->merchant_id)
        ->and($employee->merchantAccount->shops->pluck('id')->all())->toContain($main->id, $berbera->id);
});
