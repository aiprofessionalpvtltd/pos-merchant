<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\EndShiftRequest;
use App\Http\Requests\API\V1\ListShiftsRequest;
use App\Http\Requests\API\V1\StartShiftRequest;
use App\Http\Requests\API\V1\UpdateShiftRequest;
use App\Http\Resources\API\V1\ShiftResource;
use App\Models\Shift;
use App\Services\ShiftService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $shifts) {}

    public function index(ListShiftsRequest $request): JsonResponse
    {
        $to = $request->filled('to') ? Carbon::parse($request->validated('to'))->endOfDay() : now()->endOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->validated('from'))->startOfDay() : $to->copy()->subDays(30)->startOfDay();

        $result = $this->shifts->paginate(
            $request->user(),
            $request->filled('employee_id') ? (int) $request->validated('employee_id') : null,
            $from,
            $to,
            (int) $request->validated('page', 1),
            (int) $request->validated('per_page', 50),
        );

        return ApiResponse::success(
            $result['items']->map(fn (Shift $shift) => (new ShiftResource($shift))->resolve())->all(),
            null,
            200,
            ['pagination' => $result['pagination'], 'summary' => $result['summary']],
        );
    }

    public function start(StartShiftRequest $request): JsonResponse
    {
        $shift = $this->shifts->start($request->user(), $request->validated('start_time'), $request->validated('idempotency_key'));

        return ApiResponse::success((new ShiftResource($this->loaded($shift)))->resolve(), 'Shift started', 201);
    }

    public function end(EndShiftRequest $request, int $id): JsonResponse
    {
        $shift = $this->shifts->end($request->user(), $id, $request->validated('end_time'), $request->validated('idempotency_key'));

        return ApiResponse::success(
            (new ShiftResource($this->loaded($shift)))->resolve(),
            'Shift ended — '.$this->shifts->formatDuration($this->shifts->seconds($shift)),
        );
    }

    public function update(UpdateShiftRequest $request, int $id): JsonResponse
    {
        $shift = $this->shifts->correct($request->user(), $id, $request->validated());

        return ApiResponse::success((new ShiftResource($shift))->resolve(), 'Shift updated');
    }

    private function loaded(Shift $shift): Shift
    {
        return $shift->load(['user.employee', 'user.merchant', 'editor']);
    }
}
