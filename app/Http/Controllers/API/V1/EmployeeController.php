<?php

namespace App\Http\Controllers\API\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\DateRangeRequest;
use App\Http\Requests\API\V1\ListEmployeesRequest;
use App\Http\Requests\API\V1\StoreEmployeeRequest;
use App\Http\Requests\API\V1\UpdateEmployeeRequest;
use App\Http\Resources\API\V1\EmployeeResource;
use App\Models\Merchant;
use App\Models\POSPermission;
use App\Services\EmployeeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function permissions(): JsonResponse
    {
        return ApiResponse::success(POSPermission::all()->map(fn (POSPermission $permission) => [
            'key' => $permission->permission_key,
            'name' => $permission->name,
            'description' => $permission->description,
        ])->all());
    }

    public function index(ListEmployeesRequest $request): JsonResponse
    {
        $result = $this->employees->paginate($this->merchant($request), $request->validated());

        $data = $result['items']
            ->map(fn ($employee) => (new EmployeeResource($employee, $result['shifts']->get($employee->user_id)))->resolve())
            ->all();

        return ApiResponse::success($data, null, 200, ['pagination' => $result['pagination']]);
    }

    public function show(DateRangeRequest $request, int $id): JsonResponse
    {
        $employee = $this->employees->find($this->merchant($request), $id);
        [$from, $to] = $this->period($request, 30);

        $resource = new EmployeeResource(
            $employee,
            $this->employees->openShifts(collect([$employee]))->get($employee->user_id),
            $this->employees->metrics($employee, $from, $to),
        );

        return ApiResponse::success($resource->resolve());
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->employees->create(
            $request->user(),
            $this->merchant($request),
            $request->validated(),
            $request->header('X-EXELO-Confirmation'),
        );

        return ApiResponse::success(
            (new EmployeeResource($employee))->resolve(),
            $employee->first_name.' can now sign in with their phone number',
            201,
        );
    }

    public function update(UpdateEmployeeRequest $request, int $id): JsonResponse
    {
        $employee = $this->employees->update($request->user(), $this->merchant($request), $id, $request->validated());

        return ApiResponse::success(
            (new EmployeeResource($employee, $this->employees->openShifts(collect([$employee]))->get($employee->user_id)))->resolve(),
            'Saved',
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $result = $this->employees->remove($request->user(), $this->merchant($request), $id);

        return ApiResponse::success([
            'id' => $result['employee']->id,
            'deleted' => true,
            'tokens_revoked' => $result['tokens_revoked'],
            'open_shift_closed' => $result['open_shift_closed'],
        ], $result['employee']->first_name.' '.$result['employee']->last_name.' removed');
    }

    public function summary(DateRangeRequest $request): JsonResponse
    {
        [$from, $to] = $this->period($request, null);

        return ApiResponse::success($this->employees->summary($this->merchant($request), $from, $to));
    }

    private function merchant(Request $request): Merchant
    {
        return $request->user()->actingMerchant()
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
    }

    /**
     * The requested window, or the last $days days, or the current month when null.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(DateRangeRequest $request, ?int $days): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->validated('to'))->endOfDay() : now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->validated('from'))->startOfDay()
            : ($days === null ? $to->copy()->startOfMonth() : $to->copy()->subDays($days)->startOfDay());

        return [$from, $to];
    }
}
