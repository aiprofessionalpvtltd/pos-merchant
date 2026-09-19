<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\EmployeePermission;
use App\Models\Merchant;
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

        $query = Employee::where('merchant_id', $merchant->id)
            ->with(['user' => fn ($user) => $user->withTrashed(), 'permissions.permission'])
            // Removed staff only show under status=disabled, so the everyday list stays clean
            ->where('status', $status === 'disabled' ? 'inactive' : 'active');

        if ($status === 'on_shift') {
            $query->whereHas('user.shifts', fn ($shift) => $shift->open());
        } elseif ($status === 'off_shift') {
            $query->whereDoesntHave('user.shifts', fn ($shift) => $shift->open());
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
        return Employee::where('merchant_id', $merchant->id)
            ->with(['user' => fn ($user) => $user->withTrashed(), 'permissions.permission'])
            ->find($id) ?? throw new ApiException('employee.not_found', 'We could not find that staff member', 404);
    }

    /**
     * @return Collection<int, Shift> the open shift of each user, keyed by user id
     */
    public function openShifts(Collection $employees): Collection
    {
        return Shift::open()->whereIn('user_id', $employees->pluck('user_id')->filter())->orderBy('id')->get()->keyBy('user_id');
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

        // A weak PIN is refused here, before the PIN confirmation is spent
        if (! empty($data['pin'])) {
            $this->auth->assertPinAcceptable($data['pin']);
        }

        if ($this->phoneTaken($phone)) {
            throw new ApiException('employee.phone_taken', 'That number already belongs to an EXELO user', 409, [], 'phone_number');
        }

        // Only now is the PIN entry spent: a request refused above does not cost another one
        $this->auth->consumeConfirmation($actor, $confirmationToken, 'employees.create');

        $employee = DB::transaction(function () use ($merchant, $data, $phone, $permissions) {
            $user = User::create([
                'name' => $data['first_name'].' '.$data['last_name'],
                'email' => $this->newEmail($phone),
                // Unusable until the employee sets their own PIN
                'password' => Hash::make(Str::random(40)),
                'user_type' => 'employee',
            ]);

            // With a PIN the employee can sign in at once; without one they set their own
            if (! empty($data['pin'])) {
                $this->auth->setPinFor($user, $data['pin']);
            }

            $employee = Employee::create([
                'user_id' => $user->id,
                'merchant_id' => $merchant->id,
                'phone_number' => $phone,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'dob' => $data['dob'],
                'role' => $data['role'],
                'status' => 'active',
            ] + $this->salaryAttributes($data));

            $this->syncPermissions($employee, $permissions);

            return $employee;
        });

        Log::info('Employee added', ['employee_id' => $employee->id, 'merchant_id' => $merchant->id, 'added_by' => $actor->id]);

        return $employee->load(['user', 'permissions.permission']);
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

        $result = DB::transaction(function () use ($employee) {
            $user = $employee->user;

            $openShifts = $user ? Shift::open()->where('user_id', $user->id)->get() : collect();
            $openShifts->each(fn (Shift $shift) => $shift->update(['end_time' => now()->format('Y-m-d H:i:s')]));

            $tokens = $user ? $user->tokens()->where('revoked', false)->get() : collect();
            $tokens->each->revoke();

            $employee->update([
                'status' => 'inactive',
                'removed_at' => now(),
                'former_phone_number' => $employee->phone_number,
                'phone_number' => '0',
            ]);

            if ($user) {
                $user->update(['email' => 'deleted'.$employee->id.'@email.com']);
                $user->delete();
            }

            return ['tokens_revoked' => $tokens->count(), 'open_shift_closed' => $openShifts->isNotEmpty()];
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
        $orders = Order::where('merchant_id', $employee->merchant_id)
            ->where('user_id', $employee->user_id)
            ->whereIn('order_status', self::SALE_STATUSES)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('count(*) as orders, coalesce(sum(total_price), 0) as sales')
            ->first();

        $shifts = Shift::where('user_id', $employee->user_id)
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
        $employees = Employee::where('merchant_id', $merchant->id)->active()->orderBy('first_name')->orderBy('id')->get();
        $userIds = $employees->pluck('user_id')->filter();

        $shifts = Shift::whereIn('user_id', $userIds)->whereNotNull('start_time')
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
                'is_on_shift' => $open->has($employee->user_id),
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

    private function phoneTaken(string $phone): bool
    {
        $variants = PhoneNumber::variants($phone);

        return Merchant::whereIn('phone_number', $variants)->exists()
            || Employee::active()->whereIn('phone_number', $variants)->exists();
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
