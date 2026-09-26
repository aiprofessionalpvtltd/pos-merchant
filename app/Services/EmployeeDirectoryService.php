<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Order;
use App\Models\Shift;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What the admin employee pages show. Read only: nothing here changes an account or a shift.
 * `listRow` expects an employee from `listQuery()`, which carries the shift and sales columns.
 */
class EmployeeDirectoryService
{
    private const RECENT_SHIFTS = 50;

    public const STATUSES = ['on_shift' => 'On shift', 'off_shift' => 'Off shift', 'disabled' => 'Disabled', 'removed' => 'Removed'];

    public function __construct(private readonly ShiftService $shifts) {}

    /**
     * Every employee of every shop, removed ones included.
     */
    public function listQuery(): Builder
    {
        return Employee::query()
            ->select('employees.*')
            ->with(['merchant', 'user', 'permissions.permission'])
            ->selectSub($this->shiftSub('COUNT(*)'), 'shifts_count')
            ->selectSub($this->shiftSub('MAX(start_time)'), 'last_shift_at')
            ->selectSub($this->shiftSub('COUNT(*)', 'AND end_time IS NULL'), 'open_shifts')
            ->selectSub($this->orderSub('COUNT(*)'), 'orders_count')
            ->selectSub($this->orderSub('COALESCE(SUM(total_price), 0)'), 'orders_total');
    }

    public function filterStatus(Builder $query, ?string $status): void
    {
        $open = '(select count(*) from shifts where shifts.user_id = employees.user_id and shifts.start_time is not null and shifts.end_time is null)';

        match ($status) {
            'removed' => $query->whereNotNull('employees.removed_at'),
            'disabled' => $query->whereNull('employees.removed_at')->where('employees.status', '!=', 'active'),
            'on_shift' => $query->whereNull('employees.removed_at')->where('employees.status', 'active')->whereRaw("$open > 0"),
            'off_shift' => $query->whereNull('employees.removed_at')->where('employees.status', 'active')->whereRaw("$open = 0"),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function listRow(Employee $employee): array
    {
        return [
            'merchant' => $employee->merchant?->business_name ?: trim($employee->merchant?->first_name.' '.$employee->merchant?->last_name),
            'name' => trim($employee->first_name.' '.$employee->last_name),
            'salary' => $this->salary($employee),
            'status' => $this->status($employee, (int) $employee->open_shifts > 0),
            'pin' => $employee->user?->hasPin() ? 'Set' : 'Not set',
            'permissions' => $employee->permissions->pluck('permission.permission_key')->filter()->implode(', '),
            'shifts' => (int) $employee->shifts_count,
            'last_shift' => $employee->last_shift_at ? \Illuminate\Support\Carbon::parse($employee->last_shift_at)->format('d M Y H:i') : null,
            'orders' => (int) $employee->orders_count,
            'sales' => Money::usd(Money::toMinor((float) $employee->orders_total, 'USD'))['display'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Employee $employee): array
    {
        $employee->loadMissing(['merchant', 'user', 'permissions.permission']);

        $shifts = Shift::where('user_id', $employee->user_id)->with('editor')->latest('id')->get();
        $open = $shifts->first(fn (Shift $shift) => $shift->start_time && ! $shift->end_time);
        $seconds = $shifts->sum(fn (Shift $shift) => $this->shifts->seconds($shift));

        $orders = Order::where('user_id', $employee->user_id)->where('merchant_id', $employee->shop_id);
        $paid = (clone $orders)->whereRaw("LOWER(order_status) IN ('complete','paid')");

        return [
            'salary' => $this->salary($employee),
            'status' => $this->status($employee, $open !== null),
            'pin' => $employee->user?->hasPin() ? 'Set' : 'Not set',
            'locked' => $employee->user?->locked_until?->isFuture() ?? false,
            'permissions' => $employee->permissions->pluck('permission')->filter()->values(),
            'open_shift' => $open ? ['started_at' => $open->start_time, 'elapsed' => $this->shifts->formatDuration($this->shifts->seconds($open))] : null,
            'shift_count' => $shifts->count(),
            'worked' => $this->shifts->formatDuration($seconds),
            'shifts' => $shifts->take(self::RECENT_SHIFTS)->map(fn (Shift $shift) => [
                'started_at' => $shift->start_time,
                'ended_at' => $shift->end_time,
                'duration' => $this->shifts->formatDuration($this->shifts->seconds($shift)),
                'is_open' => $shift->end_time === null,
                'edited_by' => $shift->editor?->name,
                'edit_reason' => $shift->edit_reason,
            ]),
            'orders' => (clone $orders)->count(),
            'paid_orders' => (clone $paid)->count(),
            'sales' => Money::usd(Money::toMinor((float) (clone $paid)->sum('total_price'), 'USD'))['display'],
            'recent_orders' => (clone $orders)->latest('id')->limit(10)->get(),
        ];
    }

    /**
     * @return array{key: string, label: string, color: string}
     */
    public function status(Employee $employee, bool $isOnShift): array
    {
        $key = match (true) {
            $employee->removed_at !== null => 'removed',
            $employee->status !== 'active' => 'disabled',
            $isOnShift => 'on_shift',
            default => 'off_shift',
        };

        return ['key' => $key, 'label' => self::STATUSES[$key], 'color' => ['on_shift' => 'success', 'off_shift' => 'secondary', 'disabled' => 'warning', 'removed' => 'danger'][$key]];
    }

    private function salary(Employee $employee): ?string
    {
        if ($employee->salary === null) {
            return null;
        }

        $amount = Money::of(Money::toMinor((float) $employee->salary, $employee->salary_currency), $employee->salary_currency)['display'];

        return $amount.' / '.$employee->salary_period;
    }

    private function shiftSub(string $aggregate, string $extra = '')
    {
        return DB::table('shifts')->selectRaw($aggregate)->whereColumn('shifts.user_id', 'employees.user_id')->whereRaw('shifts.start_time IS NOT NULL '.$extra);
    }

    private function orderSub(string $aggregate)
    {
        return DB::table('orders')->selectRaw($aggregate)
            ->whereColumn('orders.user_id', 'employees.user_id')
            ->whereColumn('orders.merchant_id', 'employees.shop_id')
            ->whereRaw("LOWER(orders.order_status) IN ('complete','paid')");
    }
}
