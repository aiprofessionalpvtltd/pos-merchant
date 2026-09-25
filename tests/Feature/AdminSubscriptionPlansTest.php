<?php

use App\Models\Invoice;
use App\Models\MerchantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

function plansAdmin(array $permissions = ['view-subscription', 'create-subscription', 'edit-subscription', 'delete-subscription']): User
{
    $admin = User::create(['name' => 'Plans Admin', 'email' => 'plans-admin@example.test', 'password' => Hash::make('x'), 'user_type' => 'admin']);

    auth()->shouldUse('web');
    $admin->givePermissionTo($permissions);
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $admin;
}

function planBody(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Platinum Package',
        'key' => 'platinum',
        'price' => '25.00',
        'price_slsh' => '200000',
        'features' => ['dashboard.full', 'reports.full'],
        'is_default' => '0',
    ];
}

function planByKey(string $key): SubscriptionPlan
{
    return SubscriptionPlan::withTrashed()->where('key', $key)->firstOrFail();
}

it('lists the plans with their subscriptions', function () {
    $rows = test()->actingAs(plansAdmin(), 'web')->getJson(route('admin.subscription-plans.index', [
        'draw' => 1, 'start' => 0, 'length' => 25,
        'columns' => [['data' => 'name', 'name' => 'name', 'searchable' => 'true', 'orderable' => 'true']],
    ]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json('data');

    $gold = collect($rows)->firstWhere('key', 'gold');

    expect($gold['price_slsh'])->toBe(92000)
        ->and($gold['features_count'])->toBe(9)
        ->and($gold)->toHaveKeys(['subscriptions_count', 'action']);
});

it('shows the create and edit pages', function () {
    $admin = plansAdmin();

    test()->actingAs($admin, 'web')->get(route('admin.subscription-plans.index'))->assertOk()->assertSee('Add plan');
    test()->actingAs($admin, 'web')->get(route('admin.subscription-plans.create'))->assertOk()->assertSee('Manage employees');
    test()->actingAs($admin, 'web')->get(route('admin.subscription-plans.edit', planByKey('gold')))->assertOk()->assertSee('Gold Package');
});

it('creates a plan the app offers straight away', function () {
    test()->actingAs(plansAdmin(), 'web')
        ->post(route('admin.subscription-plans.store'), planBody())
        ->assertRedirect(route('admin.subscription-plans.index'))
        ->assertSessionHas('success');

    $plan = planByKey('platinum');

    expect($plan->only(['name', 'price_slsh', 'duration', 'features', 'is_default']))->toBe([
        'name' => 'Platinum Package', 'price_slsh' => 200000, 'duration' => 'monthly',
        'features' => ['dashboard.full', 'reports.full'], 'is_default' => false,
    ]);

    $offered = collect(test()->getJson('/api/v1/plans')->assertOk()->json('data.plans'))->firstWhere('key', 'platinum');

    expect($offered['price']['amount'])->toBe(200000)
        ->and($offered['features'])->toBe(['dashboard.full', 'reports.full']);
});

it('rejects a bad key, an unknown feature and a key used before', function () {
    $admin = plansAdmin();

    test()->actingAs($admin, 'web')
        ->post(route('admin.subscription-plans.store'), planBody(['key' => 'Bad Key!', 'features' => ['made.up']]))
        ->assertSessionHasErrors(['key', 'features.0']);

    planByKey('gold')->delete();

    test()->actingAs($admin, 'web')
        ->post(route('admin.subscription-plans.store'), planBody(['key' => 'gold', 'name' => 'New Gold']))
        ->assertSessionHasErrors(['key']);
});

it('updates a plan but never its key', function () {
    $gold = planByKey('gold');

    test()->actingAs(plansAdmin(), 'web')
        ->put(route('admin.subscription-plans.update', $gold), planBody(['name' => 'Gold Plus', 'key' => 'changed', 'price_slsh' => '95,000']))
        ->assertRedirect(route('admin.subscription-plans.index'));

    expect($gold->fresh()->only(['name', 'key', 'price_slsh']))->toBe(['name' => 'Gold Plus', 'key' => 'gold', 'price_slsh' => 95000]);
});

it('moves the default to another plan, keeping exactly one', function () {
    test()->actingAs(plansAdmin(), 'web')
        ->put(route('admin.subscription-plans.update', planByKey('gold')), planBody(['name' => 'Gold Package', 'is_default' => '1']));

    expect(SubscriptionPlan::default()->pluck('key')->all())->toBe(['gold']);
});

it('refuses to leave the catalogue without a default plan', function () {
    $silver = planByKey('silver');

    test()->actingAs(plansAdmin(), 'web')
        ->from(route('admin.subscription-plans.edit', $silver))
        ->put(route('admin.subscription-plans.update', $silver), planBody(['name' => 'Silver Package', 'price' => 0, 'price_slsh' => 0, 'is_default' => '0']))
        ->assertRedirect(route('admin.subscription-plans.edit', $silver))
        ->assertSessionHas('error');

    expect($silver->fresh()->is_default)->toBeTrue();
});

it('deletes an unused plan, which the app then stops offering', function () {
    $admin = plansAdmin();
    test()->actingAs($admin, 'web')->post(route('admin.subscription-plans.store'), planBody());

    test()->actingAs($admin, 'web')
        ->delete(route('admin.subscription-plans.destroy', planByKey('platinum')))
        ->assertSessionHas('success');

    expect(planByKey('platinum')->trashed())->toBeTrue()
        ->and(collect(test()->getJson('/api/v1/plans')->json('data.plans'))->pluck('key'))->not->toContain('platinum');
});

it('refuses to delete the default plan or a plan merchants are on', function () {
    $admin = plansAdmin();
    $owner = makeMerchant('2468');
    $gold = planByKey('gold');

    MerchantSubscription::create([
        'merchant_id' => $owner->merchant->id, 'subscription_plan_id' => $gold->id,
        'start_date' => now()->subDays(3)->toDateString(), 'end_date' => now()->addDays(27)->toDateString(), 'transaction_status' => 'Paid',
    ]);

    test()->actingAs($admin, 'web')->delete(route('admin.subscription-plans.destroy', $gold))->assertSessionHas('error');
    test()->actingAs($admin, 'web')->delete(route('admin.subscription-plans.destroy', planByKey('silver')))->assertSessionHas('error');

    expect($gold->fresh()->trashed())->toBeFalse()
        ->and(planByKey('silver')->trashed())->toBeFalse();
});

it('refuses to delete a plan with a payment still open', function () {
    $admin = plansAdmin();
    test()->actingAs($admin, 'web')->post(route('admin.subscription-plans.store'), planBody());
    $plan = planByKey('platinum');

    Invoice::create([
        'public_id' => Invoice::generatePublicId(), 'invoice_id' => 'CASH-plan', 'transaction_id' => 'cash_plan', 'hash' => '0',
        'mobile_number' => '+252634990002', 'amount' => 200000, 'currency' => 'SLSH', 'status' => 'Pending',
        'type' => 'Subscription', 'rail' => 'cash', 'subscription_plan_id' => $plan->id,
    ]);

    test()->actingAs($admin, 'web')->delete(route('admin.subscription-plans.destroy', $plan))->assertSessionHas('error');

    expect($plan->fresh()->trashed())->toBeFalse();
});

it('needs the subscription permissions', function () {
    $viewer = plansAdmin(['view-subscription']);

    test()->actingAs($viewer, 'web')->get(route('admin.subscription-plans.create'))->assertForbidden();
    test()->actingAs($viewer, 'web')->post(route('admin.subscription-plans.store'), planBody())->assertForbidden();
    test()->actingAs($viewer, 'web')->put(route('admin.subscription-plans.update', planByKey('gold')), planBody())->assertForbidden();
    test()->actingAs($viewer, 'web')->delete(route('admin.subscription-plans.destroy', planByKey('gold')))->assertForbidden();

    expect(SubscriptionPlan::where('key', 'platinum')->exists())->toBeFalse();
});
