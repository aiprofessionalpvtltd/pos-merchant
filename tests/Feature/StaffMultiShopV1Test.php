<?php

use App\Models\Employee;
use App\Models\Shift;
use App\Models\Shop;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

const MULTI_STAFF_PHONE = '+252634660201';

/**
 * A merchant with two Gold shops and one person who works in both (inventory in one, POS in the other).
 *
 * @return array{0: Shop, 1: Shop, 2: User, 3: string}
 */
function staffInTwoShops(): array
{
    [, $main, $berbera] = teamOwner();
    $owner = teamLogin();

    // Added to the first shop as a new person, with a PIN.
    test()->withToken($owner)->withHeader('X-EXELO-Confirmation', teamConfirmation($owner))
        ->postJson('/api/v1/employees', teamNewStaff(['phone_number' => MULTI_STAFF_PHONE, 'first_name' => 'Ayaan', 'permission_keys' => ['inventory'], 'pin' => '2468', 'pin_confirmation' => '2468']))
        ->assertCreated()
        ->assertJsonPath('data.existing_person', false);

    app('auth')->forgetGuards();

    return [$main, $berbera, User::find(Employee::where('phone_number', MULTI_STAFF_PHONE)->value('user_id')), $owner];
}

function staffLogin(array $extra = []): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/auth/pin/login', ['phone_number' => MULTI_STAFF_PHONE, 'pin' => '2468'] + $extra, TEAM_DEVICE);
}

it('adds someone who already works in another shop as the same person, keeping their PIN', function () {
    [$main, $berbera, $person, $owner] = staffInTwoShops();

    test()->withToken($owner)->withHeader('X-EXELO-Confirmation', teamConfirmation($owner))
        ->postJson('/api/v1/employees', teamNewStaff(['phone_number' => MULTI_STAFF_PHONE, 'first_name' => 'Ayaan', 'permission_keys' => ['pos'], 'shop_id' => $berbera->id, 'pin' => '9999', 'pin_confirmation' => '9999']))
        ->assertCreated()
        ->assertJsonPath('data.existing_person', true)
        ->assertJsonPath('message', 'Ayaan now also works in Hodan Berbera. They sign in with their existing PIN.');

    expect(Employee::where('user_id', $person->id)->where('status', 'active')->pluck('merchant_id')->sort()->values()->all())
        ->toBe([$main->id, $berbera->id])
        ->and(User::where('user_type', 'employee')->whereIn('id', Employee::where('phone_number', MULTI_STAFF_PHONE)->pluck('user_id'))->count())->toBe(1);

    // Still the original PIN; the one sent with the second shop is ignored.
    staffLogin()->assertOk();

    app('auth')->forgetGuards();
    test()->withToken($owner)->withHeader('X-EXELO-Confirmation', teamConfirmation($owner))
        ->postJson('/api/v1/employees', teamNewStaff(['phone_number' => MULTI_STAFF_PHONE, 'shop_id' => $berbera->id]))
        ->assertStatus(409)->assertJsonPath('error.code', 'employee.already_in_shop');
});

it('lets the person pick a shop at sign-in and gives them that shop\'s permissions', function () {
    [$main, $berbera, , $owner] = staffInTwoShops();
    test()->withToken($owner)->withHeader('X-EXELO-Confirmation', teamConfirmation($owner))
        ->postJson('/api/v1/employees', teamNewStaff(['phone_number' => MULTI_STAFF_PHONE, 'permission_keys' => ['pos'], 'shop_id' => $berbera->id]))->assertCreated();

    app('auth')->forgetGuards();
    $token = staffLogin()->assertOk()
        ->assertJsonPath('data.merchant.id', $main->id)
        ->assertJsonCount(2, 'data.shops')
        ->assertJsonPath('data.shops.0.role', 'staff')
        ->assertJsonPath('data.permissions.0.key', 'inventory')
        ->json('data.token');

    app('auth')->forgetGuards();
    test()->withToken($token)->postJson("/api/v1/shops/{$berbera->id}/select")
        ->assertOk()
        ->assertJsonPath('data.merchant.id', $berbera->id)
        ->assertJsonPath('data.permissions.0.key', 'pos');

    // In Berbera they have POS, not inventory.
    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/products')->assertForbidden();

    app('auth')->forgetGuards();
    staffLogin(['shop_id' => $berbera->id])->assertOk()->assertJsonPath('data.merchant.id', $berbera->id);
});

it('keeps shifts and hours separate per shop', function () {
    [$main, $berbera, $person, $owner] = staffInTwoShops();
    test()->withToken($owner)->withHeader('X-EXELO-Confirmation', teamConfirmation($owner))
        ->postJson('/api/v1/employees', teamNewStaff(['phone_number' => MULTI_STAFF_PHONE, 'permission_keys' => ['pos'], 'shop_id' => $berbera->id]))->assertCreated();

    app('auth')->forgetGuards();
    $token = staffLogin()->json('data.token');

    // Clocked in at the main shop…
    app('auth')->forgetGuards();
    test()->withToken($token)->postJson('/api/v1/shifts/start', ['idempotency_key' => 'multi-1'])->assertCreated();

    // …and, separately, at Berbera.
    app('auth')->forgetGuards();
    test()->withToken($token)->postJson("/api/v1/shops/{$berbera->id}/select")->assertOk()->assertJsonPath('data.shift.active', false);

    app('auth')->forgetGuards();
    test()->withToken($token)->postJson('/api/v1/shifts/start', ['idempotency_key' => 'multi-2'])->assertCreated();

    expect(Shift::open()->where('user_id', $person->id)->pluck('merchant_id')->sort()->values()->all())->toBe([$main->id, $berbera->id]);

    // The owner's staff list for Berbera shows them on shift there only.
    $mainEmployee = Employee::where('user_id', $person->id)->where('merchant_id', $main->id)->first();
    $berberaEmployee = Employee::where('user_id', $person->id)->where('merchant_id', $berbera->id)->first();

    app('auth')->forgetGuards();
    $rows = collect(test()->withToken($owner)->getJson('/api/v1/account/employees')->assertOk()->json('data.employees'));

    expect($rows->firstWhere('id', $mainEmployee->id)['status'])->toBe('on_shift')
        ->and($rows->firstWhere('id', $berberaEmployee->id)['status'])->toBe('on_shift');

    // Hours: an hour-long closed shift at the main shop counts only there.
    Shift::where('user_id', $person->id)->forShop($main->id)->update(['start_time' => now()->subHours(2), 'end_time' => now()->subHour()]);

    app('auth')->forgetGuards();
    test()->withToken($owner)->getJson("/api/v1/employees/{$mainEmployee->id}")->assertOk()->assertJsonPath('data.metrics.working_hours', 1);
});

it('removes the person from one shop and keeps the other', function () {
    [$main, $berbera, $person, $owner] = staffInTwoShops();
    test()->withToken($owner)->withHeader('X-EXELO-Confirmation', teamConfirmation($owner))
        ->postJson('/api/v1/employees', teamNewStaff(['phone_number' => MULTI_STAFF_PHONE, 'permission_keys' => ['pos'], 'shop_id' => $berbera->id]))->assertCreated();

    app('auth')->forgetGuards();
    $inMain = staffLogin()->json('data.token');
    app('auth')->forgetGuards();
    $inBerbera = test()->postJson('/api/v1/auth/pin/login', ['phone_number' => MULTI_STAFF_PHONE, 'pin' => '2468', 'shop_id' => $berbera->id], ['X-EXELO-Device-Id' => 'berbera-till'])->json('data.token');

    $mainEmployee = Employee::where('user_id', $person->id)->where('merchant_id', $main->id)->first();

    app('auth')->forgetGuards();
    test()->withToken($owner)->deleteJson("/api/v1/employees/{$mainEmployee->id}")
        ->assertOk()
        ->assertJsonPath('data.still_works_elsewhere', true)
        ->assertJsonPath('data.tokens_revoked', 1);

    expect($person->fresh()->trashed())->toBeFalse();

    app('auth')->forgetGuards();
    test()->withToken($inMain)->getJson('/api/v1/auth/session')->assertUnauthorized();

    app('auth')->forgetGuards();
    test()->withToken($inBerbera)->getJson('/api/v1/auth/session')->assertOk()->assertJsonCount(1, 'data.shops');

    // Removed from the last shop: the person's sign-in goes. Staff endpoints act on the
    // session's current shop, so the owner switches to Berbera first.
    $berberaEmployee = Employee::where('user_id', $person->id)->where('merchant_id', $berbera->id)->first();

    app('auth')->forgetGuards();
    test()->withToken($owner)->postJson("/api/v1/shops/{$berbera->id}/select")->assertOk();

    app('auth')->forgetGuards();
    test()->withToken($owner)->deleteJson("/api/v1/employees/{$berberaEmployee->id}")->assertOk()->assertJsonPath('data.still_works_elsewhere', false);

    expect(User::withTrashed()->find($person->id)->trashed())->toBeTrue();

    // Removal frees the number for reuse, so it is no longer a sign-in at all.
    app('auth')->forgetGuards();
    staffLogin()->assertUnauthorized()->assertJsonPath('error.code', 'auth.invalid_credentials');
});

it('refuses a merchant\'s or a shop\'s number as staff', function () {
    [, $main] = teamOwner();
    $owner = teamLogin();

    foreach ([TEAM_OWNER_PHONE, $main->phone_number] as $phone) {
        app('auth')->forgetGuards();
        test()->withToken($owner)->withHeader('X-EXELO-Confirmation', teamConfirmation($owner))
            ->postJson('/api/v1/employees', teamNewStaff(['phone_number' => $phone]))
            ->assertStatus(409)->assertJsonPath('error.code', 'employee.phone_taken');
    }
});

it('puts shifts created without a shop into the person\'s shop', function () {
    [$main, , $person] = staffInTwoShops();

    $shift = Shift::create(['user_id' => $person->id, 'start_time' => now()->subMinutes(5)]);

    expect($shift->merchant_id)->toBe($main->id);
});
