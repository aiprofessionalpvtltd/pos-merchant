<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\EmployeePermission;
use App\Models\Merchant;
use App\Models\MerchantAccount;
use App\Models\Order;
use App\Models\POSPermission;
use App\Models\Shift;
use App\Models\User;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Staff accounts of one shop: who they are, what they may do, and how they perform.
 */
class EmployeeService
{
    /** Orders in these states count as sales. */
    private const SALE_STATUSES = ['Paid', 'Complete'];

    public function __construct(
        private readonly AuthService $auth,
        private readonly SubscriptionService $subscriptions,
        private readonly ShiftService $shifts,
    ) {}

    /**
     * @param  array{status?: ?string, q?: ?string, page?: int, per_page?: int}  $filters
     * @return array{items: Collection<int, Employee>, shifts: Collection<int, Shift>, pagination: array<string, int|bool>}
     */
    public function paginate(Merchant $merchant, array $filters): array
    {
        $status = $filters['status'] ?? null;
        $search = trim($filters['q'] ?? '');

        $query = Employee::where('shop_id', $merchant->id)
            ->with(['user' => fn ($user) => $user->withTrashed(), 'permissions.permission', 'shop'])
            // Removed staff only show under status=disabled, so the everyday list stays clean
            ->where('status', $status === 'disabled' ? 'inactive' : 'active');

        // On shift in this shop (a person may be clocked in elsewhere).
        if ($status === 'on_shift') {
            $query->whereHas('user.shifts', fn ($shift) => $shift->open()->forShop($merchant->id));
        } elseif ($status === 'off_shift') {
            $query->whereDoesntHave('user.shifts', fn ($shift) => $shift->open()->forShop($merchant->id));
        }

        if ($search !== '') {
            $query->where(fn ($inner) => $inner->whereRaw("concat_ws(' ', first_name, last_name) like ?", ["%{$search}%"])
                ->orWhere('phone_number', 'like', "%{$search}%"));
        }

        $page = $query->orderBy('first_name')->orderBy('id')
            ->paginate($filters['per_page'] ?? 50, ['*'], 'page', $filters['page'] ?? 1);

        return [
            'items' => $page->getCollection(),
            'shifts' => $this->openShifts($page->getCollection()),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'total_pages' => $page->lastPage(),
                'has_more' => $page->hasMorePages(),
            ],
        ];
    }

    public function find(Merchant $merchant, int $id): Employee
    {
        return Employee::where('shop_id', $merchant->id)
            ->with(['user' => fn ($user) => $user->withTrashed(), 'permissions.permission', 'shop'])
            ->find($id) ?? throw new ApiException('employee.not_found', 'We could not find that staff member', 404);
    }

    /**
     * The shop a new staff member joins: the one asked for (owners only, any of their
     * shops), else the session's current shop.
     */
    public function targetShop(User $actor, Merchant $current, ?int $shopId): Merchant
    {
        if ($shopId === null || $shopId === $current->id) {
            return $current;
        }

        // Staff managers act on their own shop only.
        if (! $actor->isMerchantAccount()) {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can add staff to another shop', 403, [], 'shop_id');
        }

        return $actor->accessibleShop($shopId)
            ?? throw new ApiException('shop.not_found', 'We could not find that shop', 404, [], 'shop_id');
    }

    /**
     * Staff of every shop the merchant owns, with per-shop counts (merchants → shops → employees).
     *
     * @param  array{shop_id?: ?int, status?: ?string, q?: ?string, page?: int, per_page?: int}  $filters
     * @return array{items: Collection<int, Employee>, shifts: Collection<int, Shift>, by_shop: array<int, array<string, mixed>>, pagination: array<string, int|bool>}
     */
    public function listForMerchant(User $owner, array $filters): array
    {
        $this->assertMerchantAccount($owner);

        $shops = $owner->accessibleShops();
        $shopIds = $shops->pluck('id');

        if (! empty($filters['shop_id']) && ! $shopIds->contains((int) $filters['shop_id'])) {
            throw new ApiException('shop.not_found', 'We could not find that shop', 404, [], 'shop_id');
        }

        $search = trim($filters['q'] ?? '');

        $query = Employee::whereIn('shop_id', empty($filters['shop_id']) ? $shopIds : [(int) $filters['shop_id']])
            ->where('status', ($filters['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active')
            ->with(['user' => fn ($user) => $user->withTrashed(), 'permissions.permission', 'shop'])
            ->when($search !== '', fn ($inner) => $inner->where(fn ($match) => $match
                ->whereRaw("concat_ws(' ', first_name, last_name) like ?", ["%{$search}%"])
                ->orWhere('phone_number', 'like', "%{$search}%")));

        $page = $query->orderBy('shop_id')->orderBy('first_name')->orderBy('id')
            ->paginate($filters['per_page'] ?? 50, ['*'], 'page', $filters['page'] ?? 1);

        $counts = Employee::active()->whereIn('shop_id', $shopIds)
            ->selectRaw('shop_id, count(*) as active')->groupBy('shop_id')->pluck('active', 'shop_id');

        return [
            'items' => $page->getCollection(),
            'shifts' => $this->openShifts($page->getCollection()),
            'by_shop' => $shops->map(fn (Merchant $shop) => [
                'shop_id' => $shop->id,
                'business_name' => $shop->business_name,
                'active' => (int) ($counts[$shop->id] ?? 0),
            ])->values()->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'total_pages' => $page->lastPage(),
                'has_more' => $page->hasMorePages(),
            ],
        ];
    }

    /**
     * Moves a staff member to another of the owner's shops. Their permissions stay; their
     * sessions end, so they sign in again into the new shop.
     */
    public function transfer(User $owner, int $employeeId, int $shopId): Employee
    {
        $this->assertMerchantAccount($owner);

        $shopIds = $owner->accessibleShops()->pluck('id');

        $employee = Employee::active()->whereIn('shop_id', $shopIds)->find($employeeId)
            ?? throw new ApiException('employee.not_found', 'We could not find that staff member', 404);

        $target = $owner->accessibleShop($shopId)
            ?? throw new ApiException('shop.not_found', 'We could not find that shop', 404, [], 'shop_id');

        // Already there, or has an earlier staff record there (one per person per shop).
        $hasRecordThere = $employee->shop_id === $target->id
            || ($employee->user_id && Employee::where('user_id', $employee->user_id)->where('shop_id', $target->id)->exists());

        if ($hasRecordThere) {
            throw new ApiException('employee.already_in_shop', 'This staff member already has a record in '.$target->business_name.'. Add them there instead.', 409, [], 'shop_id');
        }

        if ($employee->user_id && Shift::open()->where('user_id', $employee->user_id)->forShop($employee->shop_id)->exists()) {
            throw new ApiException('employee.shift_open', 'This staff member is clocked in. End their shift first.', 409);
        }

        $this->subscriptions->requireFeature($target, 'employees.manage');

        $fromShopId = $employee->shop_id;

        // Sessions working in the old shop end; any in their other shops carry on.
        $revoked = DB::transaction(function () use ($employee, $target, $fromShopId) {
            $employee->update(['shop_id' => $target->id]);

            return $employee->user ? $employee->user->tokens()->where('merchant_id', $fromShopId)->delete() : 0;
        });

        Log::info('Employee transferred', [
            'employee_id' => $employee->id, 'from_shop_id' => $fromShopId, 'to_shop_id' => $target->id,
            'tokens_revoked' => $revoked, 'moved_by' => $owner->id,
        ]);

        return $employee->fresh(['user', 'permissions.permission', 'shop']);
    }

    /**
     * Each staff record's open shift in its own shop, keyed by employee id (a person
     * who works in two shops can be clocked in at one and not the other).
     *
     * @return Collection<int, Shift>
     */
    public function openShifts(Collection $employees): Collection
    {
        $open = Shift::open()->whereIn('user_id', $employees->pluck('user_id')->filter())->orderBy('id')->get();

        return $employees
            ->mapWithKeys(fn (Employee $employee) => [$employee->id => $open->first(
                fn (Shift $shift) => $shift->user_id === $employee->user_id && $shift->merchant_id === $employee->shop_id,
            )])
            ->filter();
    }

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public function create(User $actor, Merchant $merchant, array $data, ?string $confirmationToken): Employee
    {
        $this->subscriptions->requireFeature($merchant, 'employees.manage');
        $this->auth->assertConfirmation($actor, $confirmationToken, 'employees.create');

        $phone = PhoneNumber::normalize($data['phone_number']);
        $permissions = $this->resolvePermissions($actor, $data['permission_keys']);

        // A merchant's or a shop's number can't be staff (a person is a merchant or staff, not both).
        if ($this->phoneTaken($phone)) {
            throw new ApiException('employee.phone_taken', 'That number already belongs to an EXELO user', 409, [], 'phone_number');
        }

        // Someone who already works in another shop joins this one as the same person, with their own PIN.
        $person = $this->existingStaffPerson($phone);

        if ($person && Employee::active()->where('user_id', $person->id)->where('shop_id', $merchant->id)->exists()) {
            throw new ApiException('employee.already_in_shop', 'This person already works in '.$merchant->business_name, 409, [], 'phone_number');
        }

        // A weak PIN is refused here, before the PIN confirmation is spent. An existing person
        // keeps their PIN, so one sent for them is ignored rather than checked.
        if (! $person && ! empty($data['pin'])) {
            $this->auth->assertPinAcceptable($data['pin']);
        }

        // Only now is the PIN entry spent: a request refused above does not cost another one
        $this->auth->consumeConfirmation($actor, $confirmationToken, 'employees.create');

        $employee = DB::transaction(function () use ($merchant, $data, $phone, $permissions, $person) {
            $user = $person ?? User::create([
                'name' => $data['first_name'].' '.$data['last_name'],
                'email' => $this->newEmail($phone),
                // Unusable until the employee sets their own PIN
                'password' => Hash::make(Str::random(40)),
                'user_type' => 'employee',
            ]);

            // With a PIN a new employee can sign in at once; without one they set their own.
            // Someone who already works elsewhere keeps the PIN they have.
            if (! $person && ! empty($data['pin'])) {
                $this->auth->setPinFor($user, $data['pin']);
            }

            $attributes = [
                'phone_number' => $phone,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'dob' => $data['dob'],
                'role' => $data['role'],
                'status' => 'active',
                'removed_at' => null,
                'former_phone_number' => null,
            ] + $this->salaryAttributes($data);

            // One staff record per person per shop: a person removed from this shop earlier gets theirs back.
            $employee = Employee::where('user_id', $user->id)->where('shop_id', $merchant->id)->first();

            if ($employee) {
                $employee->update($attributes);
            } else {
                $employee = Employee::create(['user_id' => $user->id, 'shop_id' => $merchant->id] + $attributes);
            }

            $this->syncPermissions($employee, $permissions);

            return $employee;
        });

        $employee->joinedAsExistingPerson = $person !== null;

        Log::info('Employee added', [
            'employee_id' => $employee->id, 'merchant_id' => $merchant->id, 'added_by' => $actor->id,
            'existing_person' => $employee->joinedAsExistingPerson,
        ]);

        return $employee->load(['user', 'permissions.permission', 'shop']);
    }

    /**
     * @param  array<string, mixed>  $data  only the fields to change
     */
    public function update(User $actor, Merchant $merchant, int $id, array $data): Employee
    {
        $employee = $this->find($merchant, $id);

        if ($employee->status !== 'active') {
            throw new ApiException('employee.not_found', 'We could not find that staff member', 404);
        }

        $permissions = array_key_exists('permission_keys', $data) ? $this->resolvePermissions($actor, $data['permission_keys']) : null;

        DB::transaction(function () use ($employee, $data, $permissions) {
            $employee->fill(array_intersect_key($data, array_flip(['first_name', 'last_name', 'dob', 'role', 'salary_period'])));

            if (array_key_exists('salary', $data)) {
                $employee->fill($this->salaryAttributes($data));
            }

            $employee->save();

            if ($employee->user) {
                $employee->user->update(['name' => $employee->first_name.' '.$employee->last_name]);
            }

            if ($permissions !== null) {
                $this->syncPermissions($employee, $permissions);
            }
        });

        Log::info('Employee updated', ['employee_id' => $employee->id, 'updated_by' => $actor->id]);

        return $this->find($merchant, $id);
    }

    /**
     * Removes a staff member: sign-in is revoked at once, an open shift is closed,
     * and their number is freed. Their orders and shifts stay.
     *
     * @return array{employee: Employee, tokens_revoked: int, open_shift_closed: bool}
     */
    public function remove(User $actor, Merchant $merchant, int $id): array
    {
        $employee = $this->find($merchant, $id);

        if ($employee->status !== 'active') {
            throw new ApiException('employee.not_found', 'We could not find that staff member', 404);
        }

        // Removes the person from this shop. Only someone who works nowhere else loses their sign-in.
        $result = DB::transaction(function () use ($employee) {
            $user = $employee->user;

            $openShifts = $user ? Shift::open()->where('user_id', $user->id)->forShop($employee->shop_id)->get() : collect();
            $openShifts->each(fn (Shift $shift) => $shift->update(['end_time' => now()->format('Y-m-d H:i:s')]));

            $employee->update([
                'status' => 'inactive',
                'removed_at' => now(),
                'former_phone_number' => $employee->phone_number,
                'phone_number' => '0',
            ]);

            $stillWorksElsewhere = $user !== null && Employee::active()->where('user_id', $user->id)->exists();

            // Elsewhere: end only the sessions working in this shop. Nowhere: end them all.
            $tokens = match (true) {
                $user === null => collect(),
                $stillWorksElsewhere => $user->tokens()->where('merchant_id', $employee->shop_id)->get(),
                default => $user->tokens()->get(),
            };
            $tokens->each->delete();

            if ($user && ! $stillWorksElsewhere) {
                $user->update(['email' => 'deleted'.$employee->id.'@email.com']);
                $user->delete();
            }

            return [
                'tokens_revoked' => $tokens->count(),
                'open_shift_closed' => $openShifts->isNotEmpty(),
                'still_works_elsewhere' => $stillWorksElsewhere,
            ];
        });

        Log::info('Employee removed', ['employee_id' => $employee->id, 'removed_by' => $actor->id]);

        return ['employee' => $employee->refresh()] + $result;
    }

    /**
     * Performance of one employee over a period.
     *
     * @return array<string, mixed>
     */
    public function metrics(Employee $employee, Carbon $from, Carbon $to): array
    {
        $orders = Order::where('merchant_id', $employee->shop_id)
            ->where('user_id', $employee->user_id)
            ->whereIn('order_status', self::SALE_STATUSES)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('count(*) as orders, coalesce(sum(total_price), 0) as sales')
            ->first();

        $shifts = Shift::where('user_id', $employee->user_id)
            ->forShop($employee->shop_id)
            ->whereNotNull('start_time')
            ->whereBetween('start_time', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->get(['start_time', 'end_time']);

        $hours = $shifts->sum(fn (Shift $shift) => $this->shifts->seconds($shift)) / 3600;
        $salesCents = Money::toMinor((float) $orders->sales, 'USD');
        $orderCount = (int) $orders->orders;

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_sales' => Money::usd($salesCents),
            'order_count' => $orderCount,
            'average_order' => Money::usd($orderCount > 0 ? intdiv($salesCents, $orderCount) : 0),
            'working_hours' => round($hours, 1),
            'shifts_worked' => $shifts->count(),
            'sales_per_hour' => Money::usd($hours > 0 ? (int) round($salesCents / $hours) : 0),
        ];
    }

    /**
     * The team KPI strip for a period.
     *
     * @return array<string, mixed>
     */
    public function summary(Merchant $merchant, Carbon $from, Carbon $to): array
    {
        $employees = Employee::where('shop_id', $merchant->id)->active()->orderBy('first_name')->orderBy('id')->get();
        $userIds = $employees->pluck('user_id')->filter();

        $shifts = Shift::whereIn('user_id', $userIds)->forShop($merchant->id)->whereNotNull('start_time')
            ->whereBetween('start_time', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->get(['user_id', 'start_time', 'end_time'])->groupBy('user_id');

        $sales = Order::where('merchant_id', $merchant->id)->whereIn('user_id', $userIds)
            ->whereIn('order_status', self::SALE_STATUSES)->whereBetween('created_at', [$from, $to])
            ->selectRaw('user_id, coalesce(sum(total_price), 0) as sales')->groupBy('user_id')->pluck('sales', 'user_id');

        $open = $this->openShifts($employees);
        $windowDays = max(1, (int) $from->diffInDays($to) + 1);

        $rows = $employees->map(function (Employee $employee) use ($shifts, $sales, $open, $windowDays) {
            $userShifts = $shifts->get($employee->user_id, collect());
            $hours = $userShifts->sum(fn (Shift $shift) => $this->shifts->seconds($shift)) / 3600;
            $days = $userShifts->map(fn (Shift $shift) => Carbon::parse($shift->start_time)->toDateString())->unique()->count();

            return [
                'employee' => $employee,
                'hours' => $hours,
                'sales_cents' => Money::toMinor((float) $sales->get($employee->user_id, 0), 'USD'),
                'payroll_cents' => $this->payrollCents($employee, $hours, $days, $windowDays),
                'is_on_shift' => $open->has($employee->id),
            ];
        });

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_employees' => $employees->count(),
            'on_shift_now' => $rows->where('is_on_shift', true)->count(),
            'total_working_hours' => (int) round($rows->sum('hours')),
            'total_salaries' => Money::usd((int) $rows->sum('payroll_cents')),
            'total_sales' => Money::usd((int) $rows->sum('sales_cents')),
            'employees' => $rows->map(fn (array $row) => [
                'id' => $row['employee']->id,
                'short_name' => $this->initials($row['employee']),
                'first_name' => $row['employee']->first_name,
                'last_name' => $row['employee']->last_name,
                'role' => $row['employee']->role,
                'status' => $row['is_on_shift'] ? 'on_shift' : 'off_shift',
                'hours' => round($row['hours'], 1),
                'sales' => Money::usd($row['sales_cents']),
            ])->values()->all(),
        ];
    }

    public function initials(Employee $employee): string
    {
        return Str::upper(Str::substr($employee->first_name, 0, 1).Str::substr($employee->last_name, 0, 1));
    }

    /**
     * What the salary costs over the period, in USD cents. Hourly pay follows hours
     * worked, daily pay follows days worked, and monthly pay is prorated by days.
     */
    private function payrollCents(Employee $employee, float $hours, int $daysWorked, int $windowDays): int
    {
        $rate = (float) $employee->salary;

        if ($rate <= 0) {
            return 0;
        }

        $cost = match ($employee->salary_period) {
            'hourly' => $rate * $hours,
            'monthly' => $rate * ($windowDays / 30),
            default => $rate * $daysWorked,
        };

        // SLSH salaries are converted at the configured rate
        return $employee->salary_currency === 'USD'
            ? (int) round($cost * 100)
            : (int) round($cost / config('exelo.conversion_rate') * 100);
    }

    /**
     * @param  array<int, string>  $keys
     * @return Collection<int, POSPermission>
     */
    private function resolvePermissions(User $actor, array $keys): Collection
    {
        $keys = array_values(array_unique($keys));
        $known = POSPermission::all()->keyBy('permission_key');
        $unknown = array_values(array_diff($keys, $known->keys()->all()));

        if ($unknown !== []) {
            throw new ApiException('employee.permission_unknown', 'Some permissions do not exist', 422, ['permission_keys' => $unknown], 'permission_keys');
        }

        // Nobody can hand out access they do not have themselves
        $missing = array_values(array_diff($keys, $actor->posPermissionKeys()));

        if ($missing !== []) {
            throw new ApiException('auth.permission_denied', 'You cannot give access you do not have', 403, ['required_permission' => $missing[0]]);
        }

        // toBase(): an Eloquent collection's only() filters by primary key, not by these keys
        return $known->toBase()->only($keys)->values();
    }

    /**
     * @param  Collection<int, POSPermission>  $permissions
     */
    private function syncPermissions(Employee $employee, Collection $permissions): void
    {
        EmployeePermission::where('employee_id', $employee->id)->delete();

        $permissions->each(fn (POSPermission $permission) => EmployeePermission::create([
            'employee_id' => $employee->id,
            'pos_permission_id' => $permission->id,
        ]));
    }

    /**
     * Money arrives in minor units (cents for USD) and is stored in major units.
     *
     * @return array<string, mixed>
     */
    private function salaryAttributes(array $data): array
    {
        $attributes = [];

        if (array_key_exists('salary', $data)) {
            $salary = $data['salary'];
            $currency = $salary ? strtoupper($salary['currency']) : null;

            $attributes['salary'] = $salary ? ($currency === 'USD' ? $salary['amount'] / 100 : $salary['amount']) : null;

            if ($currency) {
                $attributes['salary_currency'] = $currency;
            }
        }

        if (! empty($data['salary_period'])) {
            $attributes['salary_period'] = $data['salary_period'];
        }

        return $attributes;
    }

    private function assertMerchantAccount(User $user): void
    {
        if (! $user->isMerchantAccount()) {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can do this', 403);
        }
    }

    /**
     * A shop's or a merchant's number: never a staff number.
     */
    private function phoneTaken(string $phone): bool
    {
        $variants = PhoneNumber::variants($phone);

        return Merchant::whereIn('phone_number', $variants)->exists()
            || MerchantAccount::whereIn('phone_number', $variants)->exists();
    }

    /**
     * The person behind an active staff number, if they still have a sign-in.
     */
    private function existingStaffPerson(string $phone): ?User
    {
        $employee = Employee::active()->whereIn('phone_number', PhoneNumber::variants($phone))->orderBy('id')->first();

        return $employee?->user_id ? User::where('user_type', 'employee')->find($employee->user_id) : null;
    }

    private function newEmail(string $phone): string
    {
        $email = substr($phone, 1).'@email.com';

        while (User::withTrashed()->where('email', $email)->exists()) {
            $email = substr($phone, 1).'+'.Str::lower(Str::random(5)).'@email.com';
        }

        return $email;
    }
}
