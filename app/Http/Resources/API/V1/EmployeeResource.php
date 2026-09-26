<?php

namespace App\Http\Resources\API\V1;

use App\Models\Employee;
use App\Models\Shift;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Employee $resource
 */
class EmployeeResource extends JsonResource
{
    /**
     * @param  Shift|null  $openShift  the employee's open shift, if any
     * @param  array<string, mixed>|null  $metrics  added on the detail view
     */
    public function __construct(Employee $employee, private readonly ?Shift $openShift = null, private readonly ?array $metrics = null)
    {
        parent::__construct($employee);
    }

    public function toArray(Request $request): array
    {
        $employee = $this->resource;
        $isDisabled = $employee->status !== 'active';

        $data = [
            'id' => $employee->id,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'short_name' => Str::upper(Str::substr($employee->first_name, 0, 1).Str::substr($employee->last_name, 0, 1)),
            'role' => $employee->role,
            'phone_number' => $employee->phone_number,
            // The shop this person works in (merchants → shops → employees).
            'shop' => ['id' => $employee->merchant_id, 'business_name' => $employee->shop?->business_name],
            'dob' => $employee->dob,
            'salary' => $employee->salary === null
                ? null
                : Money::of(Money::toMinor((float) $employee->salary, $employee->salary_currency), $employee->salary_currency),
            'salary_period' => $employee->salary_period,
            'status' => match (true) {
                $isDisabled => 'disabled',
                $this->openShift !== null => 'on_shift',
                default => 'off_shift',
            },
            'has_pin' => $employee->user?->hasPin() ?? false,
            'permissions' => $employee->permissions
                ->pluck('permission')->filter()
                ->map(fn ($permission) => ['key' => $permission->permission_key, 'name' => $permission->name])
                ->values()->all(),
            'current_shift' => $this->openShift ? [
                'shift_id' => $this->openShift->id,
                'started_at' => ApiResponse::iso($this->openShift->start_time),
                'elapsed_seconds' => max(0, (int) Carbon::parse($this->openShift->start_time)->diffInSeconds(now())),
            ] : null,
            'created_at' => ApiResponse::iso($employee->created_at),
        ];

        if ($this->metrics !== null) {
            $data['metrics'] = $this->metrics;
        }

        return $data;
    }
}
