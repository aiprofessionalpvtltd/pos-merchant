<?php

use App\Models\Employee;
use App\Models\MerchantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

/**
 * An admin who can view the users page. The page lists users created after the admin.
 */
function usersPageAdmin(): User
{
    $admin = User::create(['name' => 'Staff Admin', 'email' => 'staff-users@example.test', 'password' => Hash::make('x'), 'user_type' => 'admin']);

    auth()->shouldUse('web');
    $admin->givePermissionTo('view-users');
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $admin;
}

it('shows phone, shop, plan and PIN status for app users, and never a PIN', function () {
    $admin = usersPageAdmin();

    // a merchant who set a PIN through the new API (hash only), on Gold
    $owner = makeMerchant('2580');
    MerchantSubscription::create([
        'merchant_id' => $owner->merchant->id, 'subscription_plan_id' => SubscriptionPlan::where('key', 'gold')->value('id'),
        'start_date' => now()->toDateString(), 'end_date' => now()->addDays(20)->toDateString(), 'transaction_status' => 'Paid',
    ]);

    // an employee with no PIN yet
    $employeeUser = User::create(['name' => 'Aisha Ali', 'email' => 'aisha-list@example.test', 'password' => Hash::make('x'), 'user_type' => 'employee']);
    Employee::create([
        'user_id' => $employeeUser->id, 'merchant_id' => $owner->merchant->id, 'phone_number' => '+252634990002',
        'first_name' => 'Aisha', 'last_name' => 'Ali', 'dob' => '1995-05-05', 'location' => 'Hargeisa', 'role' => 'Cashier', 'salary' => 100, 'status' => 'active',
    ]);

    // a legacy user whose old plaintext PIN is still stored
    User::create(['name' => 'Legacy User', 'email' => 'legacy-list@example.test', 'password' => Hash::make('4321'), 'pin' => '4321', 'user_type' => 'merchant']);

    $html = $this->actingAs($admin, 'web')->get(route('show-user'))->assertOk()->getContent();

    expect($html)
        ->toContain('+252634990001')          // owner phone
        ->toContain('Exelo Retail')            // business
        ->toContain('Gold')                    // plan
        ->toContain('+252634990002')          // employee phone
        ->toContain('Not set')                 // employee has no PIN
        ->toContain('Set</span>')              // owner has a PIN
        ->not->toContain('4321')               // a stored PIN is never printed
        ->not->toContain('`');
});

it('flags a locked account with when it unlocks', function () {
    $admin = usersPageAdmin();
    $owner = makeMerchant('2580');
    $owner->forceFill(['pin_failed_attempts' => 0, 'locked_until' => now()->addMinutes(10)])->save();

    $this->actingAs($admin, 'web')->get(route('show-user'))
        ->assertOk()
        ->assertSee('Locked until');
});

it('does not run a query per user when listing', function () {
    $admin = usersPageAdmin();
    foreach (range(1, 6) as $i) {
        $user = User::create(['name' => "Shop {$i}", 'email' => "shop{$i}-list@example.test", 'password' => Hash::make('x'), 'user_type' => 'merchant', 'pin_set_at' => now()]);
        App\Models\Merchant::create(['user_id' => $user->id, 'phone_number' => '+25263499010'.$i, 'first_name' => 'S', 'last_name' => (string) $i, 'business_name' => "Shop {$i}", 'is_approved' => true]);
    }

    DB::enableQueryLog();
    $this->actingAs($admin, 'web')->get(route('show-user'))->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // with per-row queries 6 shops would add dozens; the eager-loaded page stays small and flat
    expect($queries)->toBeLessThan(25);
});

it('denies the page without the view-users permission', function () {
    $admin = User::create(['name' => 'No Perms', 'email' => 'noperms-users@example.test', 'password' => Hash::make('x'), 'user_type' => 'admin']);

    $this->actingAs($admin, 'web')->get(route('show-user'))->assertForbidden();
});
