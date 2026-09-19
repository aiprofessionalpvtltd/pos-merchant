<?php

use App\Models\Invoice;
use App\Models\MerchantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionDirectoryService;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

function subPlanId(string $key): int
{
    return SubscriptionPlan::where('key', $key)->value('id');
}

/**
 * The merchant's current Gold row, on top of the Silver row makeMerchant() creates.
 */
function currentGold(User $owner, int $daysLeft = 20, array $extra = []): MerchantSubscription
{
    return MerchantSubscription::create($extra + [
        'merchant_id' => $owner->merchant->id, 'subscription_plan_id' => subPlanId('gold'),
        'start_date' => now()->addDays($daysLeft)->subMonth()->toDateString(), 'end_date' => now()->addDays($daysLeft)->toDateString(),
        'transaction_status' => 'Paid',
    ]);
}

function subscriptionRow(MerchantSubscription $row): array
{
    return app(SubscriptionDirectoryService::class)->listRow($row->fresh(['merchant', 'subscriptionPlan', 'nextPlan', 'invoice']));
}

it('lists every row with its real state, who paid and any scheduled change', function () {
    $admin = merchantPagesAdmin(['view-merchant', 'edit-merchant']);
    $owner = makeMerchant('2580');

    $invoice = Invoice::create([
        'public_id' => Invoice::generatePublicId(), 'merchant_id' => $owner->merchant->id, 'invoice_id' => 'CASH-x', 'transaction_id' => 'cash_x',
        'hash' => '0', 'mobile_number' => '+252634990001', 'amount' => 92000, 'currency' => 'SLSH', 'status' => 'Paid',
        'type' => 'Subscription', 'rail' => 'cash', 'subscription_plan_id' => subPlanId('gold'),
    ]);
    currentGold($owner, 20, ['invoice_id' => $invoice->id, 'next_plan_id' => subPlanId('silver')]);

    $rows = test()->actingAs($admin, 'web')->getJson(route('admin.subscriptions.index', [
        'draw' => 1, 'start' => 0, 'length' => 10,
        'search' => ['value' => '+252634990001'],
        'columns' => [['data' => 'phone_number', 'name' => 'merchant.phone_number', 'searchable' => 'true', 'orderable' => 'false']],
    ]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json('data');

    $gold = collect($rows)->firstWhere('subscription_plan_name', 'Gold Package');
    $silver = collect($rows)->firstWhere('subscription_plan_name', 'Silver Package');

    expect($rows)->toHaveCount(2)
        ->and($gold['status'])->toBe(['key' => 'active', 'label' => 'Active'])
        ->and($gold['paid_by'])->toBe('Cash')
        ->and($gold['scheduled_plan'])->toBe('Silver')
        ->and($silver['status'])->toBe(['key' => 'past', 'label' => 'Past'])
        // only the newest row can be changed, so an older one gets no edit link
        ->and($silver['action'])->not->toContain('/edit')
        ->and($gold['action'])->toContain('/edit');
});

it('works out each state from the dates, cancellation and soft deletes', function () {
    $owner = makeMerchant('2580');

    $cancelled = currentGold($owner, 20, ['is_canceled' => true, 'canceled_at' => now()]);
    expect(subscriptionRow($cancelled)['state']['key'])->toBe('cancelled');

    $cancelled->update(['is_canceled' => false, 'end_date' => now()->subDays(2)->toDateString()]);
    expect(subscriptionRow($cancelled)['state']['key'])->toBe('grace');

    $cancelled->update(['end_date' => now()->subDays(30)->toDateString()]);
    expect(subscriptionRow($cancelled)['state']['key'])->toBe('expired');

    $replaced = MerchantSubscription::where('merchant_id', $owner->merchant->id)->where('subscription_plan_id', subPlanId('silver'))->first();
    $replaced->delete();
    expect(subscriptionRow(MerchantSubscription::withTrashed()->find($replaced->id))['state']['key'])->toBe('replaced');
});

it('requires view-merchant for the list and edit-merchant for the edit page and update', function () {
    $viewer = merchantPagesAdmin(['view-merchant']);
    $owner = makeMerchant('2580');
    $row = currentGold($owner);

    test()->actingAs($viewer, 'web')->getJson(route('admin.subscriptions.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    test()->actingAs($viewer, 'web')->get(route('edit-subscriptions', $row->id))->assertForbidden();
    test()->actingAs($viewer, 'web')->put(route('update-subscriptions', $row->id), ['subscription_plan_id' => subPlanId('silver')])->assertForbidden();

    expect($row->fresh()->subscription_plan_id)->toBe(subPlanId('gold'));
});

it('denies the list to an admin without view-merchant', function () {
    $admin = merchantPagesAdmin([]);

    test()->actingAs($admin, 'web')->get(route('admin.subscriptions.index'))->assertForbidden();
});

it('shows the edit form with the real plans, and locks it for an older row', function () {
    $admin = merchantPagesAdmin(['view-merchant', 'edit-merchant']);
    $owner = makeMerchant('2580');
    $silverRow = MerchantSubscription::where('merchant_id', $owner->merchant->id)->first();
    $goldRow = currentGold($owner);

    test()->actingAs($admin, 'web')->get(route('edit-subscriptions', $goldRow->id))
        ->assertOk()
        ->assertSee('Gold Package')
        ->assertSee('92,000 SLSH')
        ->assertSee('name="end_date"', false)
        ->assertDontSee('cannot be changed');

    test()->actingAs($admin, 'web')->get(route('edit-subscriptions', $silverRow->id))
        ->assertOk()
        ->assertSee('not the merchant');
});

it('gives a paid plan an end date, clears cancellation, and never leaves Gold without an expiry', function () {
    $admin = merchantPagesAdmin(['view-merchant', 'edit-merchant']);
    $owner = makeMerchant('2580');
    $silverRow = MerchantSubscription::where('merchant_id', $owner->merchant->id)->first();

    // Silver has no end date, so moving it to Gold without one is refused
    test()->actingAs($admin, 'web')->put(route('update-subscriptions', $silverRow->id), ['subscription_plan_id' => subPlanId('gold')])
        ->assertSessionHasErrors('end_date');
    expect($silverRow->fresh()->subscription_plan_id)->toBe(subPlanId('silver'));

    $endDate = now()->addDays(30)->toDateString();
    test()->actingAs($admin, 'web')->put(route('update-subscriptions', $silverRow->id), ['subscription_plan_id' => subPlanId('gold'), 'end_date' => $endDate])
        ->assertRedirect(route('admin.subscriptions.index'))
        ->assertSessionHas('success');

    expect($silverRow->fresh())
        ->subscription_plan_id->toBe(subPlanId('gold'))
        ->end_date->toBe($endDate);
});

it('resets cancellation and a scheduled downgrade when an admin changes the plan', function () {
    $admin = merchantPagesAdmin(['view-merchant', 'edit-merchant']);
    $owner = makeMerchant('2580');
    $gold = currentGold($owner, 20, ['is_canceled' => true, 'canceled_at' => now(), 'cancel_reason' => 'too_expensive', 'next_plan_id' => subPlanId('silver')]);

    test()->actingAs($admin, 'web')->put(route('update-subscriptions', $gold->id), ['subscription_plan_id' => subPlanId('gold'), 'end_date' => now()->addDays(60)->toDateString()])
        ->assertSessionHas('success');

    expect($gold->fresh())
        ->is_canceled->toBeFalse()
        ->canceled_at->toBeNull()
        ->cancel_reason->toBeNull()
        ->next_plan_id->toBeNull();
});

it('drops the end date when moving to the free plan', function () {
    $admin = merchantPagesAdmin(['view-merchant', 'edit-merchant']);
    $owner = makeMerchant('2580');
    $gold = currentGold($owner);

    test()->actingAs($admin, 'web')->put(route('update-subscriptions', $gold->id), ['subscription_plan_id' => subPlanId('silver')])
        ->assertSessionHas('success');

    expect($gold->fresh())->subscription_plan_id->toBe(subPlanId('silver'))->end_date->toBeNull();
});

it('rejects an unknown plan, a past end date and changing an older row', function () {
    $admin = merchantPagesAdmin(['view-merchant', 'edit-merchant']);
    $owner = makeMerchant('2580');
    $silverRow = MerchantSubscription::where('merchant_id', $owner->merchant->id)->first();
    $goldRow = currentGold($owner);

    test()->actingAs($admin, 'web')->put(route('update-subscriptions', $goldRow->id), ['subscription_plan_id' => 999999])
        ->assertSessionHasErrors('subscription_plan_id');

    test()->actingAs($admin, 'web')->put(route('update-subscriptions', $goldRow->id), ['subscription_plan_id' => subPlanId('gold'), 'end_date' => now()->subDay()->toDateString()])
        ->assertSessionHasErrors('end_date');

    // an older row is history: changing it would not change what the shop can use
    test()->actingAs($admin, 'web')->put(route('update-subscriptions', $silverRow->id), ['subscription_plan_id' => subPlanId('gold'), 'end_date' => now()->addDays(30)->toDateString()])
        ->assertSessionHas('error');

    expect($silverRow->fresh()->subscription_plan_id)->toBe(subPlanId('silver'))
        ->and($goldRow->fresh()->subscription_plan_id)->toBe(subPlanId('gold'));
});

it('tells the merchant page why a shop without subscription rows has an empty history', function () {
    $admin = merchantPagesAdmin(['view-merchant']);
    $owner = makeMerchant('2580');
    MerchantSubscription::where('merchant_id', $owner->merchant->id)->forceDelete();

    test()->actingAs($admin, 'web')->get(route('view-merchant', $owner->merchant->id))
        ->assertOk()
        ->assertSee('No subscription record for this shop')
        ->assertSee('Silver');
});
