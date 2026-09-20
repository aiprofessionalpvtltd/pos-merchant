<?php

use App\Models\Shift;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

function staffShop(array $permissions = ['view-employee']): array
{
    $owner = makeMerchant('2580');

    return [$owner, merchantPagesAdmin($permissions)];
}

function staffRows($response)
{
    return collect($response->assertOk()->json('data'))->keyBy('phone_number');
}

it('lists every employee with shop, role, salary, access, shifts and completed sales', function () {
    [$owner, $admin] = staffShop();
    $layla = makeStaff($owner, '+252634990701', ['pos', 'inventory']);

    Shift::create(['user_id' => $layla->user_id, 'start_time' => now()->subDays(2)->setTime(8, 0), 'end_time' => now()->subDays(2)->setTime(16, 0)]);
    Shift::create(['user_id' => $layla->user_id, 'start_time' => now()->subHour(), 'end_time' => null]);
    makeSale($owner, $layla->user_id, 20.5, 'Complete');
    makeSale($owner, $layla->user_id, 10, 'Paid');
    makeSale($owner, $layla->user_id, 99, 'Pending');

    $row = staffRows(table($admin, 'admin.employees.index', [], ['phone_number'], '+252634990701'))['+252634990701'];

    expect($row['merchant'])->toBe('Exelo Retail')
        ->and($row['name'])->toBe('Layla Ahmed')
        ->and($row['role'])->toBe('Cashier')
        ->and($row['salary_display'])->toBe('$4.50 / daily')
        ->and($row['status_label'])->toBe('On shift')
        ->and($row['pin'])->toBe('Set')
        ->and($row['permission_keys'])->toContain('pos')->toContain('inventory')
        ->and($row['shifts'])->toBe(2)
        ->and($row['last_shift'])->not->toBeNull()
        ->and($row['sales'])->toBe('$30.50')
        ->and($row['action'])->toContain('/admin/employees/'.$row['id']);
});

it('marks an employee off shift, disabled or removed', function () {
    [$owner, $admin] = staffShop();
    makeStaff($owner, '+252634990702', ['pos']);
    makeStaff($owner, '+252634990703', ['pos'], ['status' => 'inactive']);
    makeStaff($owner, '+252634990704', ['pos'], ['status' => 'inactive', 'removed_at' => now()]);

    $rows = staffRows(table($admin, 'admin.employees.index', [], ['phone_number'], '+2526349907'));

    expect($rows['+252634990702']['status_label'])->toBe('Off shift')
        ->and($rows['+252634990703']['status_label'])->toBe('Disabled')
        ->and($rows['+252634990704']['status_label'])->toBe('Removed');
});

it('filters employees on shift', function () {
    [$owner, $admin] = staffShop();
    $working = makeStaff($owner, '+252634990705', ['pos']);
    makeStaff($owner, '+252634990706', ['pos']);
    Shift::create(['user_id' => $working->user_id, 'start_time' => now()->subHour(), 'end_time' => null]);

    $rows = staffRows(table($admin, 'admin.employees.index', ['status' => 'on_shift'], ['phone_number'], '+2526349907'));

    expect($rows->keys()->all())->toBe(['+252634990705']);
});

it('filters employees off shift', function () {
    [$owner, $admin] = staffShop();
    $working = makeStaff($owner, '+252634990707', ['pos']);
    makeStaff($owner, '+252634990708', ['pos']);
    Shift::create(['user_id' => $working->user_id, 'start_time' => now()->subHour(), 'end_time' => null]);

    $rows = staffRows(table($admin, 'admin.employees.index', ['status' => 'off_shift'], ['phone_number'], '+2526349907'));

    expect($rows->keys()->all())->toBe(['+252634990708']);
});

it('filters removed employees', function () {
    [$owner, $admin] = staffShop();
    makeStaff($owner, '+252634990709', ['pos']);
    makeStaff($owner, '+252634990710', ['pos'], ['status' => 'inactive', 'removed_at' => now()]);

    $rows = staffRows(table($admin, 'admin.employees.index', ['status' => 'removed'], ['phone_number'], '+2526349907'));

    expect($rows->keys()->all())->toBe(['+252634990710']);
});

it('finds an employee by name', function () {
    [$owner, $admin] = staffShop();
    makeStaff($owner, '+252634990711', ['pos'], ['first_name' => 'Zainab']);
    makeStaff($owner, '+252634990712', ['pos'], ['first_name' => 'Yusuf']);

    $rows = collect(table($admin, 'admin.employees.index', [], ['name'], 'Zainab')->assertOk()->json('data'));

    expect($rows->pluck('phone_number')->all())->toBe(['+252634990711']);
});

it('shows an employee with details, access, shifts and orders', function () {
    [$owner, $admin] = staffShop();
    $layla = makeStaff($owner, '+252634990713', ['pos', 'transactions']);
    $editor = $owner;

    Shift::create(['user_id' => $layla->user_id, 'start_time' => now()->subDays(2)->setTime(8, 0), 'end_time' => now()->subDays(2)->setTime(16, 30), 'edited_by' => $editor->id, 'edited_at' => now(), 'edit_reason' => 'Forgot to clock out']);
    Shift::create(['user_id' => $layla->user_id, 'start_time' => now()->subMinutes(90), 'end_time' => null]);
    $sale = makeSale($owner, $layla->user_id, 42.5, 'Complete');

    test()->actingAs($admin, 'web')->get(route('admin.employees.view', $layla->id))
        ->assertOk()
        ->assertSee('Layla Ahmed')
        ->assertSee('+252634990713')
        ->assertSee('Exelo Retail')
        ->assertSee('Cashier')
        ->assertSee('$4.50 / daily')
        ->assertSee('On shift')
        ->assertSee('8h 30m')
        ->assertSee('Corrected by Kalid Ahmed: Forgot to clock out')
        ->assertSee('$42.50')
        ->assertSee('#'.$sale->id)
        ->assertSee('Set');

    test()->actingAs($admin, 'web')->get(route('admin.employees.view', 999999))->assertNotFound();
});

it('shows a removed employee with the number they had', function () {
    [$owner, $admin] = staffShop();
    $gone = makeStaff($owner, '+252634990714', ['pos'], ['status' => 'inactive', 'removed_at' => now(), 'former_phone_number' => '+252634990714', 'phone_number' => 'removed-1']);

    test()->actingAs($admin, 'web')->get(route('admin.employees.view', $gone->id))
        ->assertOk()->assertSee('Removed')->assertSee('was +252634990714');
});

it('keeps the employee pages behind their permission', function () {
    $owner = makeMerchant('2580');
    $staff = makeStaff($owner, '+252634990715', ['pos']);
    $noAccess = merchantPagesAdmin(['view-invoice']);

    table($noAccess, 'admin.employees.index')->assertForbidden();
    test()->actingAs($noAccess, 'web')->get(route('admin.employees.view', $staff->id))->assertForbidden();

    auth()->forgetGuards();
    test()->get(route('admin.employees.index'))->assertRedirect();
});

it('shows the Employees menu entry to users who may open the page', function () {
    $admin = merchantPagesAdmin(['view-employee']);

    test()->actingAs($admin, 'web')->get(route('admin.employees.index'))->assertOk()->assertSee(route('admin.employees.index'), false);
});
