<?php

use App\Models\Employee;
use App\Models\EmployeePermission;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\POSPermission;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

/**
 * A staff member of the owner's shop, able to sign in with PIN 2580.
 *
 * @param  array<int, string>  $keys  permission keys
 */
function makeStaff(User $owner, string $phone, array $keys = ['pos'], array $overrides = []): Employee
{
    $user = User::create(['name' => 'Staff '.$phone, 'email' => "staff{$phone}@example.test", 'password' => Hash::make('2580'), 'user_type' => 'employee', 'pin_set_at' => now()]);

    $employee = Employee::create($overrides + [
        'user_id' => $user->id, 'shop_id' => $owner->merchant->id, 'phone_number' => $phone,
        'first_name' => 'Layla', 'last_name' => 'Ahmed', 'dob' => '1998-04-12', 'role' => 'Cashier',
        'salary' => 4.5, 'salary_currency' => 'USD', 'salary_period' => 'daily', 'status' => 'active',
    ]);

    foreach ($keys as $key) {
        EmployeePermission::create([
            'employee_id' => $employee->id,
            'pos_permission_id' => POSPermission::all()->first(fn ($permission) => $permission->permission_key === $key)->id,
        ]);
    }

    return $employee;
}

function staffToken(string $phone, string $pin = '2580'): string
{
    return test()->postJson('/api/v1/auth/pin/login', ['phone_number' => $phone, 'pin' => $pin], device('staff-device'))->json('data.token');
}

/**
 * A fresh PIN confirmation for the signed-in owner, as the app gets from POST /auth/pin/verify.
 */
function confirmation(string $token, string $scope = 'employees.create'): string
{
    return test()->withToken($token)->postJson('/api/v1/auth/pin/verify', ['pin' => '2580', 'scope' => $scope])->json('data.confirmation_token');
}

function shiftRow(User $user, string $start, ?string $end = null): Shift
{
    return Shift::create(['user_id' => $user->id, 'start_time' => $start, 'end_time' => $end]);
}

/**
 * A sale taken by a user. The orders table needs the VAT and fee columns, which do not matter here.
 */
function makeSale(User $owner, int $userId, float $total, string $status = 'Paid'): Order
{
    return Order::forceCreate([
        'merchant_id' => $owner->merchant->id, 'user_id' => $userId, 'order_status' => $status, 'total_price' => $total,
        'sub_total' => $total, 'vat' => 0, 'exelo_amount' => 0, 'name' => 'Customer', 'mobile_number' => '1', 'order_type' => 'shop',
    ]);
}

function newStaffBody(array $overrides = []): array
{
    return $overrides + [
        'first_name' => 'Nasra', 'last_name' => 'Yusuf', 'phone_number' => '+252634990303', 'dob' => '2000-01-19',
        'role' => 'Cashier', 'salary' => ['amount' => 450, 'currency' => 'USD'], 'salary_period' => 'daily',
        'permission_keys' => ['pos', 'inventory'],
    ];
}

it('lists the assignable permissions with stable keys', function () {
    makeMerchant('2580');
    $token = ownerToken();

    $this->withToken($token)->getJson('/api/v1/permissions')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'pos')
        ->assertJsonPath('data.3.key', 'reports')
        ->assertJsonPath('data.4.key', 'employees')
        ->assertJsonPath('data.4.description', 'Add and manage staff');

    app('auth')->forgetGuards();
    $this->withoutToken()->getJson('/api/v1/permissions')->assertStatus(401);
});

it('lets only staff with the employees permission manage employees', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    makeStaff($owner, '+252634110001', ['pos']);
    makeStaff($owner, '+252634110002', ['employees'], ['first_name' => 'Boss']);

    $this->withToken(staffToken('+252634110001'))->getJson('/api/v1/employees')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'auth.permission_denied')
        ->assertJsonPath('error.details.required_permission', 'employees');

    app('auth')->forgetGuards();
    $this->withToken(staffToken('+252634110002'))->getJson('/api/v1/employees')->assertOk();

    app('auth')->forgetGuards();
    $this->withToken(ownerToken())->getJson('/api/v1/employees')->assertOk();
});

it('lists employees with real statuses, filters, search and pagination', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    $token = ownerToken();

    $onShift = makeStaff($owner, '+252634110011', ['pos', 'inventory'], ['first_name' => 'Anab']);
    $offShift = makeStaff($owner, '+252634110012', ['pos'], ['first_name' => 'Bilan']);
    makeStaff($owner, '+252634110013', ['pos'], ['first_name' => 'Cali', 'status' => 'inactive']);
    shiftRow($onShift->user, now()->subHours(3)->format('Y-m-d H:i:s'));

    $all = $this->withToken($token)->getJson('/api/v1/employees')->assertOk();
    expect(collect($all->json('data'))->pluck('first_name')->all())->toBe(['Anab', 'Bilan'])
        ->and($all->json('meta.pagination.total'))->toBe(2);

    $first = $all->json('data.0');
    expect($first['status'])->toBe('on_shift')
        ->and($first['short_name'])->toBe('AA')
        ->and($first['has_pin'])->toBeTrue()
        ->and($first['salary'])->toBe(['amount' => 450, 'currency' => 'USD', 'display' => '$4.50'])
        ->and($first['salary_period'])->toBe('daily')
        ->and($first['permissions'])->toHaveCount(2)
        ->and($first['current_shift']['elapsed_seconds'])->toBeGreaterThan(3 * 3600 - 5);

    expect($all->json('data.1.status'))->toBe('off_shift')->and($all->json('data.1.current_shift'))->toBeNull();

    $this->withToken($token)->getJson('/api/v1/employees?status=on_shift')->assertJsonPath('data.0.first_name', 'Anab')->assertJsonCount(1, 'data');
    $this->withToken($token)->getJson('/api/v1/employees?status=off_shift')->assertJsonPath('data.0.first_name', 'Bilan')->assertJsonCount(1, 'data');
    $this->withToken($token)->getJson('/api/v1/employees?status=disabled')->assertJsonPath('data.0.first_name', 'Cali')->assertJsonPath('data.0.status', 'disabled');
    $this->withToken($token)->getJson('/api/v1/employees?q=bil')->assertJsonCount(1, 'data');
    $this->withToken($token)->getJson('/api/v1/employees?q=110011')->assertJsonPath('data.0.first_name', 'Anab');
    $this->withToken($token)->getJson('/api/v1/employees?per_page=1')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.pagination.has_more', true)
        ->assertJsonPath('meta.pagination.total_pages', 2);
    $this->withToken($token)->getJson('/api/v1/employees?status=nonsense')->assertStatus(422);
});

it('shows one employee with sales and hours for a period', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    $employee = makeStaff($owner, '+252634110021', ['pos']);

    shiftRow($employee->user, now()->subDays(2)->setTime(6, 0)->format('Y-m-d H:i:s'), now()->subDays(2)->setTime(14, 0)->format('Y-m-d H:i:s'));
    shiftRow($employee->user, now()->subDay()->setTime(6, 0)->format('Y-m-d H:i:s'), now()->subDay()->setTime(14, 0)->format('Y-m-d H:i:s'));
    // outside the 30-day window
    shiftRow($employee->user, now()->subDays(60)->format('Y-m-d H:i:s'), now()->subDays(60)->addHours(8)->format('Y-m-d H:i:s'));

    foreach ([10.50, 20.50] as $total) {
        makeSale($owner, $employee->user_id, $total);
    }
    makeSale($owner, $employee->user_id, 99, 'Pending');

    $metrics = $this->withToken(ownerToken())->getJson("/api/v1/employees/{$employee->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $employee->id)
        ->json('data.metrics');

    expect($metrics['order_count'])->toBe(2)
        ->and($metrics['total_sales'])->toBe(['amount' => 3100, 'currency' => 'USD', 'display' => '$31.00'])
        ->and($metrics['average_order']['amount'])->toBe(1550)
        ->and($metrics['working_hours'])->toEqual(16)
        ->and($metrics['shifts_worked'])->toBe(2)
        ->and($metrics['sales_per_hour']['amount'])->toBe(194)
        ->and($metrics['period']['to'])->toBe(now()->toDateString());
});

it('does not show another shop\'s employee', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    $other = Merchant::create(['phone_number' => '+252634990077', 'first_name' => 'Other']);
    $stranger = Employee::create([
        'shop_id' => $other->id, 'phone_number' => '+252634110099', 'first_name' => 'Zed', 'last_name' => 'Z', 'dob' => '1990-01-01', 'role' => 'x', 'status' => 'active',
    ]);
    $token = ownerToken();

    $this->withToken($token)->getJson("/api/v1/employees/{$stranger->id}")->assertStatus(404)->assertJsonPath('error.code', 'employee.not_found');
    $this->withToken($token)->patchJson("/api/v1/employees/{$stranger->id}", ['first_name' => 'Hacked'])->assertStatus(404);
    $this->withToken($token)->deleteJson("/api/v1/employees/{$stranger->id}")->assertStatus(404);
    expect($stranger->fresh()->first_name)->toBe('Zed')->and($stranger->fresh()->status)->toBe('active');
});

it('adds an employee with a PIN so they can look up their shop and sign in at once', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    $token = ownerToken();

    $response = $this->withToken($token)->withHeader('X-EXELO-Confirmation', confirmation($token))
        ->postJson('/api/v1/employees', newStaffBody(['pin' => '2468', 'pin_confirmation' => '2468']))
        ->assertCreated()
        ->assertJsonPath('data.has_pin', true)
        ->assertJsonMissingPath('data.pin');

    // only a hash is stored, never the PIN itself
    $user = Employee::find($response->json('data.id'))->user;
    expect($user->pin)->toBeNull()->and(Hash::check('2468', $user->password))->toBeTrue();

    app('auth')->forgetGuards();
    $this->withoutToken()->postJson('/api/v1/auth/lookup', ['phone_number' => '+252634990303'])
        ->assertJsonPath('data.user_type', 'employee')
        ->assertJsonPath('data.business_name', 'Exelo Retail')
        ->assertJsonPath('data.has_pin', true);

    $this->withoutToken()->postJson('/api/v1/auth/pin/login', ['phone_number' => '+252634990303', 'pin' => '2468'], device('staff-device'))
        ->assertOk()
        ->assertJsonPath('data.user.type', 'employee')
        ->assertJsonPath('data.merchant.business_name', 'Exelo Retail')
        ->assertJsonPath('data.permissions.0.key', 'pos');

    $this->withoutToken()->postJson('/api/v1/auth/pin/login', ['phone_number' => '+252634990303', 'pin' => '0001'], device('staff-device'))->assertStatus(401);
});

it('refuses a bad or weak staff PIN without spending the PIN confirmation', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    $token = ownerToken();
    $confirm = confirmation($token);

    foreach (['1234', '0000', '7777'] as $weak) {
        $this->withToken($token)->withHeader('X-EXELO-Confirmation', $confirm)
            ->postJson('/api/v1/employees', newStaffBody(['pin' => $weak, 'pin_confirmation' => $weak]))
            ->assertStatus(422)->assertJsonPath('error.code', 'auth.pin_too_weak');
    }

    $this->withToken($token)->withHeader('X-EXELO-Confirmation', $confirm)
        ->postJson('/api/v1/employees', newStaffBody(['pin' => '2468', 'pin_confirmation' => '2469']))
        ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
    $this->withToken($token)->withHeader('X-EXELO-Confirmation', $confirm)
        ->postJson('/api/v1/employees', newStaffBody(['pin' => '24', 'pin_confirmation' => '24']))
        ->assertStatus(422);

    expect(Employee::where('phone_number', '+252634990303')->exists())->toBeFalse();

    // nothing was created and the confirmation still works for a corrected request
    $this->withToken($token)->withHeader('X-EXELO-Confirmation', $confirm)
        ->postJson('/api/v1/employees', newStaffBody(['pin' => '2468', 'pin_confirmation' => '2468']))->assertCreated();
});

it('adds an employee with a PIN confirmation, who then sets a PIN and signs in', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    $token = ownerToken();

    $response = $this->withToken($token)->withHeader('X-EXELO-Confirmation', confirmation($token))
        ->postJson('/api/v1/employees', newStaffBody())
        ->assertCreated()
        ->assertJsonPath('message', 'Nasra can now sign in with their phone number')
        ->assertJsonPath('data.phone_number', '+252634990303')
        ->assertJsonPath('data.status', 'off_shift')
        ->assertJsonPath('data.has_pin', false)
        ->assertJsonPath('data.salary.display', '$4.50')
        ->assertJsonPath('data.permissions.1.key', 'inventory');

    // the stored salary is in major units
    expect((float) Employee::find($response->json('data.id'))->salary)->toBe(4.5);

    // nobody can sign in with a default PIN; the employee sets their own
    app('auth')->forgetGuards();
    $this->withoutToken()->postJson('/api/v1/auth/lookup', ['phone_number' => '+252634990303'])
        ->assertJsonPath('data.user_type', 'employee')->assertJsonPath('data.has_pin', false);

    $this->withoutToken()->postJson('/api/v1/auth/pin', ['phone_number' => '+252634990303', 'pin' => '2468', 'pin_confirmation' => '2468'], device('staff-device'))
        ->assertCreated()
        ->assertJsonPath('data.user.type', 'employee')
        ->assertJsonPath('data.permissions.0.key', 'pos');
});

it('needs a fresh single-use PIN confirmation with the right scope', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    $token = ownerToken();

    $this->withToken($token)->postJson('/api/v1/employees', newStaffBody())
        ->assertStatus(401)->assertJsonPath('error.code', 'auth.confirmation_required');

    $this->withToken($token)->withHeader('X-EXELO-Confirmation', 'cnf_MADEUP')->postJson('/api/v1/employees', newStaffBody())->assertStatus(401);

    $this->withToken($token)->withHeader('X-EXELO-Confirmation', confirmation($token, 'something.else'))
        ->postJson('/api/v1/employees', newStaffBody())->assertStatus(401);

    $good = confirmation($token);
    $this->withToken($token)->withHeader('X-EXELO-Confirmation', $good)->postJson('/api/v1/employees', newStaffBody())->assertCreated();
    $this->withToken($token)->withHeader('X-EXELO-Confirmation', $good)->postJson('/api/v1/employees', newStaffBody(['phone_number' => '+252634990304']))
        ->assertStatus(401);
    expect(Employee::where('phone_number', '+252634990304')->exists())->toBeFalse();
});

it('does not spend the PIN confirmation when the request is refused', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    makeStaff($owner, '+252634990303', ['pos']);
    $token = ownerToken();
    $confirm = confirmation($token);

    // The number is already staff in this shop.
    $this->withToken($token)->withHeader('X-EXELO-Confirmation', $confirm)->postJson('/api/v1/employees', newStaffBody())
        ->assertStatus(409)->assertJsonPath('error.code', 'employee.already_in_shop');

    // the same confirmation still works for a corrected request
    $this->withToken($token)->withHeader('X-EXELO-Confirmation', $confirm)->postJson('/api/v1/employees', newStaffBody(['phone_number' => '+252634110305']))->assertCreated();
});

it('refuses staff management on the Silver plan', function () {
    makeMerchant('2580');
    $token = ownerToken();

    $this->withToken($token)->withHeader('X-EXELO-Confirmation', confirmation($token))->postJson('/api/v1/employees', newStaffBody())
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'plan.feature_unavailable')
        ->assertJsonPath('error.details.feature', 'employees.manage')
        ->assertJsonPath('error.details.required_plan', 'gold');
});

it('rejects bad employee input and unknown permissions', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    $token = ownerToken();

    $this->withToken($token)->postJson('/api/v1/employees', ['first_name' => 'X'])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    $this->withToken($token)->withHeader('X-EXELO-Confirmation', confirmation($token))
        ->postJson('/api/v1/employees', newStaffBody(['permission_keys' => ['pos', 'root']]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'employee.permission_unknown')
        ->assertJsonPath('error.details.permission_keys', ['root']);

    // the owner's own number cannot be reused for staff either
    $this->withToken($token)->withHeader('X-EXELO-Confirmation', confirmation($token))
        ->postJson('/api/v1/employees', newStaffBody(['phone_number' => '0634 990 001']))
        ->assertStatus(409)->assertJsonPath('error.code', 'employee.phone_taken');
});

it('stops staff from handing out permissions they do not have', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    makeStaff($owner, '+252634110002', ['employees', 'pos'], ['first_name' => 'Boss']);
    $token = staffToken('+252634110002');

    $this->withToken($token)->withHeader('X-EXELO-Confirmation', confirmation($token))
        ->postJson('/api/v1/employees', newStaffBody(['permission_keys' => ['pos', 'reports']]))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'auth.permission_denied')
        ->assertJsonPath('error.details.required_permission', 'reports');

    $this->withToken($token)->withHeader('X-EXELO-Confirmation', confirmation($token))
        ->postJson('/api/v1/employees', newStaffBody(['permission_keys' => ['pos']]))->assertCreated();
});

it('updates any subset of fields and replaces the permission set', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    $employee = makeStaff($owner, '+252634110031', ['pos', 'inventory']);
    $token = ownerToken();

    $this->withToken($token)->patchJson("/api/v1/employees/{$employee->id}", [
        'first_name' => 'Nasra', 'salary' => ['amount' => 500, 'currency' => 'USD'], 'permission_keys' => ['reports'],
    ])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Nasra')
        ->assertJsonPath('data.last_name', 'Ahmed')
        ->assertJsonPath('data.salary.display', '$5.00')
        ->assertJsonCount(1, 'data.permissions')
        ->assertJsonPath('data.permissions.0.key', 'reports');

    expect($employee->user->fresh()->name)->toBe('Nasra Ahmed');

    // leaving permission_keys out keeps them
    $this->withToken($token)->patchJson("/api/v1/employees/{$employee->id}", ['role' => 'Supervisor'])
        ->assertOk()->assertJsonPath('data.role', 'Supervisor')->assertJsonPath('data.permissions.0.key', 'reports');

    $this->withToken($token)->patchJson("/api/v1/employees/{$employee->id}", ['permission_keys' => ['root']])->assertStatus(422);
    $this->withToken($token)->patchJson("/api/v1/employees/{$employee->id}", ['salary' => null])->assertOk()->assertJsonPath('data.salary', null);
});

it('removes an employee: revokes sign-in, closes the shift, frees the number, keeps history', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    $employee = makeStaff($owner, '+252634110041', ['pos'], ['first_name' => 'Nasra', 'last_name' => 'Yusuf']);
    $shift = shiftRow($employee->user, now()->subHours(2)->format('Y-m-d H:i:s'));
    $staff = staffToken('+252634110041');
    $token = ownerToken();

    $this->withToken($token)->deleteJson("/api/v1/employees/{$employee->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Nasra Yusuf removed')
        ->assertJsonPath('data.deleted', true)
        ->assertJsonPath('data.tokens_revoked', 1)
        ->assertJsonPath('data.open_shift_closed', true);

    expect($shift->fresh()->end_time)->not->toBeNull()
        ->and($employee->fresh())
        ->status->toBe('inactive')
        ->former_phone_number->toBe('+252634110041')
        ->removed_at->not->toBeNull();

    app('auth')->forgetGuards();
    $this->withToken($staff)->getJson('/api/v1/auth/session')->assertStatus(401);

    // history stays readable, and the number can be given to someone new
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/employees?status=disabled')->assertJsonPath('data.0.first_name', 'Nasra');
    $this->withToken($token)->getJson("/api/v1/shifts?employee_id={$employee->id}")->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($token)->withHeader('X-EXELO-Confirmation', confirmation($token))
        ->postJson('/api/v1/employees', newStaffBody(['phone_number' => '+252634110041']))->assertCreated();

    $this->withToken($token)->deleteJson("/api/v1/employees/{$employee->id}")->assertStatus(404);
});

it('summarises the team: hours, sales and payroll for hourly, daily and monthly pay', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    $from = now()->subDays(29)->toDateString();
    $to = now()->toDateString();

    $hourly = makeStaff($owner, '+252634110051', ['pos'], ['first_name' => 'Hodan', 'salary' => 2, 'salary_currency' => 'USD', 'salary_period' => 'hourly']);
    $daily = makeStaff($owner, '+252634110052', ['pos'], ['first_name' => 'Idil', 'salary' => 10500, 'salary_currency' => 'SLSH', 'salary_period' => 'daily']);
    $monthly = makeStaff($owner, '+252634110053', ['pos'], ['first_name' => 'Jama', 'salary' => 30, 'salary_currency' => 'USD', 'salary_period' => 'monthly']);

    // Hodan: one 10 hour shift and still on shift; Idil: two separate days
    shiftRow($hourly->user, now()->subDays(3)->setTime(6, 0)->format('Y-m-d H:i:s'), now()->subDays(3)->setTime(16, 0)->format('Y-m-d H:i:s'));
    shiftRow($hourly->user, now()->subMinutes(30)->format('Y-m-d H:i:s'));
    shiftRow($daily->user, now()->subDays(2)->setTime(6, 0)->format('Y-m-d H:i:s'), now()->subDays(2)->setTime(8, 0)->format('Y-m-d H:i:s'));
    shiftRow($daily->user, now()->subDays(2)->setTime(9, 0)->format('Y-m-d H:i:s'), now()->subDays(2)->setTime(11, 0)->format('Y-m-d H:i:s'));
    shiftRow($daily->user, now()->subDay()->setTime(6, 0)->format('Y-m-d H:i:s'), now()->subDay()->setTime(8, 0)->format('Y-m-d H:i:s'));
    makeSale($owner, $hourly->user_id, 40, 'Complete');

    $summary = $this->withToken(ownerToken())->getJson("/api/v1/employees/summary?from={$from}&to={$to}")->assertOk()->json('data');

    // Hodan 10.5h x $2 = $21.00; Idil worked 2 days x 10,500 SLSH = $2.00; Jama $30 for 30 days = $30.00
    expect($summary['total_employees'])->toBe(3)
        ->and($summary['on_shift_now'])->toBe(1)
        ->and($summary['total_working_hours'])->toBe(17) // 10.5h + 6h = 16.5h, rounded
        ->and($summary['total_salaries']['amount'])->toBe(2100 + 200 + 3000)
        ->and($summary['total_sales'])->toBe(['amount' => 4000, 'currency' => 'USD', 'display' => '$40.00']);

    $rows = collect($summary['employees']);
    expect($rows->pluck('first_name')->all())->toBe(['Hodan', 'Idil', 'Jama'])
        ->and($rows->firstWhere('first_name', 'Hodan')['status'])->toBe('on_shift')
        ->and($rows->firstWhere('first_name', 'Hodan')['hours'])->toBe(10.5)
        ->and($rows->firstWhere('first_name', 'Hodan')['sales']['amount'])->toBe(4000)
        ->and($rows->firstWhere('first_name', 'Idil')['status'])->toBe('off_shift');
});

it('clocks in and out, with one open shift at a time', function () {
    $owner = makeMerchant('2580');
    $token = ownerToken();

    $shift = $this->withToken($token)->postJson('/api/v1/shifts/start', ['start_time' => now()->subHours(8)->toIso8601String()])
        ->assertCreated()
        ->assertJsonPath('message', 'Shift started')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.end_time', null)
        ->json('data');

    expect($shift['duration_seconds'])->toBeGreaterThanOrEqual(8 * 3600);

    $this->withToken($token)->postJson('/api/v1/shifts/start')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'shift.already_active')
        ->assertJsonPath('error.details.shift.id', $shift['id']);

    $this->withToken($token)->postJson("/api/v1/shifts/{$shift['id']}/end")
        ->assertOk()
        ->assertJsonPath('message', 'Shift ended — 8h 00m')
        ->assertJsonPath('data.is_active', false);

    $this->withToken($token)->postJson("/api/v1/shifts/{$shift['id']}/end")->assertStatus(409)->assertJsonPath('error.code', 'shift.already_ended');
    $this->withToken($token)->postJson('/api/v1/shifts/start')->assertCreated();
    expect(Shift::where('user_id', $owner->id)->count())->toBe(2);
});

it('does not double-start or double-end when a request is retried with the same key', function () {
    $owner = makeMerchant('2580');
    $token = ownerToken();

    $first = $this->withToken($token)->postJson('/api/v1/shifts/start', ['idempotency_key' => 'start-1'])->assertCreated()->json('data.id');
    $this->withToken($token)->postJson('/api/v1/shifts/start', ['idempotency_key' => 'start-1'])->assertCreated()->assertJsonPath('data.id', $first);
    expect(Shift::where('user_id', $owner->id)->count())->toBe(1);

    $this->withToken($token)->postJson("/api/v1/shifts/{$first}/end", ['idempotency_key' => 'end-1'])->assertOk();
    $this->withToken($token)->postJson("/api/v1/shifts/{$first}/end", ['idempotency_key' => 'end-1'])->assertOk();
    $this->withToken($token)->postJson("/api/v1/shifts/{$first}/end", ['idempotency_key' => 'end-2'])->assertStatus(409);
});

it('rejects impossible shift times and other people\'s shifts', function () {
    $owner = makeMerchant('2580');
    $employee = makeStaff($owner, '+252634110061', ['pos']);
    $theirs = shiftRow($employee->user, now()->subHours(3)->format('Y-m-d H:i:s'));
    $token = ownerToken();

    $this->withToken($token)->postJson('/api/v1/shifts/start', ['start_time' => now()->addHour()->toIso8601String()])
        ->assertStatus(422)->assertJsonPath('error.code', 'shift.time_in_future');

    $mine = $this->withToken($token)->postJson('/api/v1/shifts/start', ['start_time' => now()->subHours(2)->toIso8601String()])->json('data.id');

    $this->withToken($token)->postJson("/api/v1/shifts/{$mine}/end", ['end_time' => now()->subHours(3)->toIso8601String()])
        ->assertStatus(422)->assertJsonPath('error.code', 'shift.end_before_start');
    $this->withToken($token)->postJson("/api/v1/shifts/{$mine}/end", ['end_time' => now()->addHours(2)->toIso8601String()])
        ->assertStatus(422)->assertJsonPath('error.code', 'shift.time_in_future');

    // you can only clock yourself out
    $this->withToken($token)->postJson("/api/v1/shifts/{$theirs->id}/end")->assertStatus(404)->assertJsonPath('error.code', 'shift.not_found');
    expect($theirs->fresh()->end_time)->toBeNull();
});

it('lists shifts with a summary, for yourself or, with permission, an employee', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    $worker = makeStaff($owner, '+252634110071', ['pos'], ['first_name' => 'Layla']);
    $lead = makeStaff($owner, '+252634110072', ['employees'], ['first_name' => 'Lead']);
    shiftRow($worker->user, now()->subDays(2)->setTime(6, 0)->format('Y-m-d H:i:s'), now()->subDays(2)->setTime(14, 30)->format('Y-m-d H:i:s'));
    $open = shiftRow($worker->user, now()->subHours(1)->format('Y-m-d H:i:s'));
    shiftRow($worker->user, now()->subDays(90)->format('Y-m-d H:i:s'), now()->subDays(90)->addHours(8)->format('Y-m-d H:i:s'));

    $mine = $this->withToken(staffToken('+252634110071'))->getJson('/api/v1/shifts')->assertOk();
    expect($mine->json('data'))->toHaveCount(2)
        ->and($mine->json('data.0.id'))->toBe($open->id)
        ->and($mine->json('data.0.is_active'))->toBeTrue()
        ->and($mine->json('data.0.employee'))->toBe(['id' => $worker->id, 'name' => 'Layla Ahmed', 'short_name' => 'LA'])
        ->and($mine->json('data.1.duration_seconds'))->toBe(8 * 3600 + 1800)
        ->and($mine->json('meta.summary.active_shift_id'))->toBe($open->id)
        ->and($mine->json('meta.summary.total_hours'))->toBeGreaterThan(9.4)
        ->and($mine->json('meta.pagination.total'))->toBe(2);

    // staff cannot read a colleague's shifts, a lead can
    app('auth')->forgetGuards();
    $this->withToken(staffToken('+252634110071'))->getJson("/api/v1/shifts?employee_id={$lead->id}")->assertStatus(403);
    app('auth')->forgetGuards();
    $this->withToken(staffToken('+252634110072'))->getJson("/api/v1/shifts?employee_id={$worker->id}")->assertOk()->assertJsonCount(2, 'data');
    app('auth')->forgetGuards();
    $this->withToken(ownerToken())->getJson('/api/v1/shifts?employee_id=999999')->assertStatus(404)->assertJsonPath('error.code', 'employee.not_found');
});

it('lets only the owner correct a shift, with a reason, and marks it edited', function () {
    $owner = makeMerchant('2580');
    goldFor($owner, 30);
    $employee = makeStaff($owner, '+252634110081', ['employees']);
    $shift = shiftRow($employee->user, now()->subDay()->setTime(6, 0)->format('Y-m-d H:i:s'));
    $token = ownerToken();
    $end = now()->subDay()->setTime(14, 30);

    $this->withToken($token)->patchJson("/api/v1/shifts/{$shift->id}", ['end_time' => $end->toIso8601String()])
        ->assertStatus(422)->assertJsonPath('error.details.reason.0', fn ($message) => str_contains($message, 'required'));

    $this->withToken($token)->patchJson("/api/v1/shifts/{$shift->id}", ['reason' => 'Forgot to clock out'])->assertStatus(422);

    $this->withToken($token)->patchJson("/api/v1/shifts/{$shift->id}", ['end_time' => $end->toIso8601String(), 'reason' => 'Forgot to clock out'])
        ->assertOk()
        ->assertJsonPath('message', 'Shift updated')
        ->assertJsonPath('data.edited', true)
        ->assertJsonPath('data.edited_by.name', 'Kalid Ahmed')
        ->assertJsonPath('data.edit_reason', 'Forgot to clock out')
        ->assertJsonPath('data.duration_seconds', 8 * 3600 + 1800)
        ->assertJsonPath('data.is_active', false);

    $this->withToken($token)->patchJson("/api/v1/shifts/{$shift->id}", ['end_time' => now()->subDays(3)->toIso8601String(), 'reason' => 'oops'])
        ->assertStatus(422)->assertJsonPath('error.code', 'shift.end_before_start');

    // even an employee with the employees permission cannot edit payroll records
    app('auth')->forgetGuards();
    $this->withToken(staffToken('+252634110081'))->patchJson("/api/v1/shifts/{$shift->id}", ['end_time' => $end->toIso8601String(), 'reason' => 'mine'])
        ->assertStatus(403)->assertJsonPath('error.code', 'auth.merchant_only');
});

it('cannot correct a shift that belongs to another shop', function () {
    $owner = makeMerchant('2580');
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger-shift@example.test', 'password' => Hash::make('x'), 'user_type' => 'merchant']);
    $foreign = shiftRow($stranger, now()->subDay()->format('Y-m-d H:i:s'), now()->subHours(20)->format('Y-m-d H:i:s'));

    $this->withToken(ownerToken())->patchJson("/api/v1/shifts/{$foreign->id}", ['end_time' => now()->subHours(10)->toIso8601String(), 'reason' => 'x'])
        ->assertStatus(404)->assertJsonPath('error.code', 'shift.not_found');
    expect($foreign->fresh()->edited_at)->toBeNull();
});
