<?php

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

/**
 * @param  array<int, string>  $permissions
 */
function merchantPagesAdmin(array $permissions = ['view-merchant']): User
{
    $admin = User::create(['name' => 'Staff Admin', 'email' => 'staff-merchants@example.test', 'password' => Hash::make('x'), 'user_type' => 'admin']);

    auth()->shouldUse('web');

    foreach ($permissions as $permission) {
        $admin->givePermissionTo($permission);
    }

    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $admin;
}

function listMerchants(User $admin, array $query = [])
{
    $query += [
        'draw' => 1, 'start' => 0, 'length' => 10,
        'columns' => [
            ['data' => 'phone_number', 'name' => 'phone_number', 'searchable' => 'true', 'orderable' => 'true'],
            ['data' => 'name', 'name' => 'name', 'searchable' => 'true', 'orderable' => 'true'],
            ['data' => 'plan', 'name' => 'plan', 'searchable' => 'false', 'orderable' => 'false'],
        ],
    ];

    return test()->actingAs($admin, 'web')->getJson(route('admin.merchant.index', $query), ['X-Requested-With' => 'XMLHttpRequest']);
}

it('lists merchants server-side with plan, PIN status and address', function () {
    $admin = merchantPagesAdmin();
    makeMerchant('2580');

    $row = listMerchants($admin, ['search' => ['value' => '+252634990001']])
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 1)
        ->json('data.0');

    expect($row['name'])->toBe('Kalid Ahmed')
        ->and($row['plan']['name'])->toBe('Silver')
        ->and($row['plan']['status'])->toBe('active')
        ->and($row['pin']['state'])->toBe('set')
        ->and($row['address'])->toBe('Hargeisa, Maroodi Jeex')
        ->and($row)->not->toHaveKey('pin_code');
});

it('searches by merchant name', function () {
    $admin = merchantPagesAdmin();
    makeMerchant('2580');

    // other merchants in the database may share the name, so check ours is among the matches
    $matches = listMerchants($admin, ['search' => ['value' => 'Kalid Ahmed']])->assertOk()->json('data');

    expect(collect($matches)->pluck('phone_number'))->toContain('+252634990001');
});

it('pages in the database instead of loading every merchant', function () {
    $admin = merchantPagesAdmin();
    makeMerchant('2580');
    Merchant::create(['phone_number' => '+252634990088', 'first_name' => 'Second', 'last_name' => 'Shop']);

    $page = listMerchants($admin, ['length' => 1])->assertOk();

    expect($page->json('data'))->toHaveCount(1)
        ->and($page->json('recordsTotal'))->toBeGreaterThanOrEqual(2);
});

it('shows a merchant with plan, PIN, wallets, staff, history and pending cash', function () {
    $admin = merchantPagesAdmin();
    $owner = makeMerchant('2580');
    $merchant = $owner->merchant;
    $merchant->update(['edahab_number' => '+252654990001']);

    $gold = SubscriptionPlan::where('key', 'gold')->value('id');
    MerchantSubscription::create([
        'merchant_id' => $merchant->id, 'subscription_plan_id' => $gold,
        'start_date' => now()->toDateString(), 'end_date' => now()->addDays(20)->toDateString(), 'transaction_status' => 'Paid',
    ]);

    $employeeUser = User::create(['name' => 'Aisha Ali', 'email' => 'aisha-merchant@example.test', 'password' => Hash::make('x'), 'user_type' => 'employee']);
    Employee::create([
        'user_id' => $employeeUser->id, 'merchant_id' => $merchant->id, 'phone_number' => '+252634990002',
        'first_name' => 'Aisha', 'last_name' => 'Ali', 'dob' => '1995-05-05', 'location' => 'Hargeisa', 'role' => 'Cashier', 'salary' => 100, 'status' => 'active',
    ]);

    Invoice::create([
        'public_id' => Invoice::generatePublicId(), 'merchant_id' => $merchant->id, 'invoice_id' => 'CASH-x', 'transaction_id' => 'cash_x',
        'hash' => '0', 'mobile_number' => '+252634990001', 'amount' => 92000, 'currency' => 'SLSH', 'status' => 'Pending',
        'type' => 'Subscription', 'rail' => 'cash', 'subscription_plan_id' => $gold,
    ]);

    $this->actingAs($admin, 'web')->get(route('view-merchant', $merchant->id))
        ->assertOk()
        ->assertSee('Exelo Retail')
        ->assertSee('Gold')
        ->assertSee('Hargeisa, Maroodi Jeex')
        ->assertSee('Payout Wallets')
        ->assertSee('+252654990001')
        ->assertSee('Aisha')
        ->assertSee('cash payment(s) waiting for confirmation')
        ->assertSee('Subscription History');
});

it('shows the PIN as Set for a PIN created through the new API, not the old plaintext check', function () {
    $admin = merchantPagesAdmin();
    $owner = makeMerchant('2580');

    expect($owner->pin)->toBeNull();

    $html = $this->actingAs($admin, 'web')->get(route('view-merchant', $owner->merchant->id))->assertOk()->getContent();

    expect($html)->toContain('badge bg-success">Set</span>');
});

it('viewing a merchant never changes their subscription', function () {
    $admin = merchantPagesAdmin();
    $owner = makeMerchant('2580');
    $silver = SubscriptionPlan::where('key', 'silver')->value('id');

    // Gold ended yesterday with a downgrade scheduled; only the API or the daily job applies it
    MerchantSubscription::create([
        'merchant_id' => $owner->merchant->id, 'subscription_plan_id' => SubscriptionPlan::where('key', 'gold')->value('id'),
        'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->subDay()->toDateString(),
        'transaction_status' => 'Paid', 'next_plan_id' => $silver,
    ]);
    $before = MerchantSubscription::where('merchant_id', $owner->merchant->id)->count();

    $this->actingAs($admin, 'web')->get(route('view-merchant', $owner->merchant->id))->assertOk();
    listMerchants($admin, ['search' => ['value' => '+252634990001']])->assertOk();

    expect(MerchantSubscription::where('merchant_id', $owner->merchant->id)->count())->toBe($before);
});

it('redirects to the merchant list when the merchant does not exist', function () {
    $admin = merchantPagesAdmin();

    $this->actingAs($admin, 'web')->get(route('view-merchant', 999999999))
        ->assertRedirect(route('admin.merchant.index'))
        ->assertSessionHas('error');
});

it('requires view-merchant to open the list or a merchant', function () {
    $admin = merchantPagesAdmin([]);
    $owner = makeMerchant('2580');

    $this->actingAs($admin, 'web')->get(route('view-merchant', $owner->merchant->id))->assertForbidden();
    listMerchants($admin)->assertForbidden();
});

it('requires delete-merchant to delete, then removes the merchant and login together', function () {
    $owner = makeMerchant('2580');
    $merchantId = $owner->merchant->id;

    $viewer = merchantPagesAdmin(['view-merchant']);
    $this->actingAs($viewer, 'web')->postJson(route('delete-merchant'), ['id' => $merchantId])->assertForbidden();
    expect(Merchant::find($merchantId))->not->toBeNull();

    $viewer->givePermissionTo('delete-merchant');
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($viewer, 'web')->postJson(route('delete-merchant'), ['id' => $merchantId])
        ->assertOk()
        ->assertJsonPath('success', 'Merchant has been deleted successfully.');

    expect(Merchant::find($merchantId))->toBeNull()
        ->and(User::find($owner->id))->toBeNull()
        ->and(User::withTrashed()->find($owner->id)->email)->toBe("deleted{$merchantId}@email.com")
        ->and(Merchant::withTrashed()->find($merchantId)->phone_number)->toBe('0');
});
