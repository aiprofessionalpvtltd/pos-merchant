<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\ListAccountEmployeesRequest;
use App\Http\Requests\API\V1\UpdateMerchantAccountRequest;
use App\Http\Resources\API\V1\EmployeeResource;
use App\Services\EmployeeService;
use App\Services\MerchantAccountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The merchant account: the person, every shop they own, and every shop's staff.
 * See docs/data-model.md.
 */
class MerchantAccountController extends Controller
{
    public function __construct(
        private readonly MerchantAccountService $accounts,
        private readonly EmployeeService $employees,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->accounts->overview($request->user()));
    }

    public function update(UpdateMerchantAccountRequest $request): JsonResponse
    {
        return ApiResponse::success($this->accounts->update($request->user(), $request->validated()), 'Your details are saved');
    }

    public function employees(ListAccountEmployeesRequest $request): JsonResponse
    {
        $result = $this->employees->listForMerchant($request->user(), $request->validated());

        $employees = $result['items']
            ->map(fn ($employee) => (new EmployeeResource($employee, $result['shifts']->get($employee->id)))->resolve())
            ->all();

        return ApiResponse::success(
            ['employees' => $employees, 'by_shop' => $result['by_shop']],
            null,
            200,
            ['pagination' => $result['pagination']],
        );
    }
}
