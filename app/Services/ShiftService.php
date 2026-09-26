<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Clock in and out, shift history, and owner corrections. A shift belongs to a
 * user in one shop (the session's current shop), so the owner can clock in too, and
 * a person who works in several shops has separate shifts and hours in each.
 * A shift is open while it has no end time.
 */
class ShiftService
{
    /** A phone's clock may run a little ahead of the server's. */
    private const CLOCK_SKEW_MINUTES = 5;

    public function seconds(Shift $shift, ?Carbon $now = null): int
    {
        $start = Carbon::parse($shift->start_time);
        $end = $shift->end_time ? Carbon::parse($shift->end_time) : ($now ?? now());

        return max(0, (int) $start->diffInSeconds($end));
    }

    public function formatDuration(int $seconds): string
    {
        return intdiv($seconds, 3600).'h '.str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT).'m';
    }

    /**
     * The user's open shift in the current shop.
     */
    public function openShift(User $user): ?Shift
    {
        return Shift::open()->where('user_id', $user->id)->forShop($this->shopId($user))->latest('id')->first();
    }

    public function start(User $user, ?string $startTime, ?string $idempotencyKey): Shift
    {
        $shopId = $this->shopId($user);
        $replayKey = $idempotencyKey ? 'shift-start:'.$user->id.':'.sha1($idempotencyKey) : null;

        if ($replayKey && ($id = Cache::get($replayKey)) && ($shift = Shift::where('user_id', $user->id)->find($id))) {
            return $shift;
        }

        // One open shift per person per shop.
        return Cache::lock('shift:'.$user->id.':'.$shopId, 10)->block(5, function () use ($user, $shopId, $startTime, $replayKey) {
            if ($open = $this->openShift($user)) {
                throw new ApiException('shift.already_active', 'You are already clocked in', 409, [
                    'shift' => ['id' => $open->id, 'started_at' => ApiResponse::iso($open->start_time)],
                ]);
            }

            $time = $startTime ? $this->parse($startTime, 'start_time') : now();

            $shift = Shift::create(['user_id' => $user->id, 'merchant_id' => $shopId, 'start_time' => $time->format('Y-m-d H:i:s')]);

            if ($replayKey) {
                Cache::put($replayKey, $shift->id, now()->addDay());
            }

            return $shift;
        });
    }

    public function end(User $user, int $shiftId, ?string $endTime, ?string $idempotencyKey): Shift
    {
        $replayKey = $idempotencyKey ? 'shift-end:'.$user->id.':'.$shiftId.':'.sha1($idempotencyKey) : null;

        $shopId = $this->shopId($user);

        return DB::transaction(function () use ($user, $shopId, $shiftId, $endTime, $replayKey) {
            $shift = Shift::where('user_id', $user->id)->forShop($shopId)->lockForUpdate()->find($shiftId)
                ?? throw new ApiException('shift.not_found', 'We could not find that shift', 404);

            if ($shift->end_time) {
                if ($replayKey && Cache::has($replayKey)) {
                    return $shift;
                }

                throw new ApiException('shift.already_ended', 'That shift has already ended', 409);
            }

            $end = $endTime ? $this->parse($endTime, 'end_time') : now();

            if ($end->lt(Carbon::parse($shift->start_time))) {
                throw new ApiException('shift.end_before_start', 'The end time is before the start time', 422, [], 'end_time');
            }

            $shift->update(['end_time' => $end->format('Y-m-d H:i:s')]);

            if ($replayKey) {
                Cache::put($replayKey, true, now()->addDay());
            }

            return $shift;
        });
    }

    /**
     * @return array{items: \Illuminate\Support\Collection<int, Shift>, pagination: array<string, int|bool>, summary: array<string, mixed>}
     */
    public function paginate(User $actor, ?int $employeeId, Carbon $from, Carbon $to, int $page, int $perPage): array
    {
        $target = $this->targetUser($actor, $employeeId);
        $shopId = $this->shopId($actor);

        $query = Shift::where('user_id', $target->id)
            ->forShop($shopId)
            ->whereNotNull('start_time')
            ->whereBetween('start_time', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);

        $paginator = (clone $query)->with(['user.employee', 'user.merchant', 'editor'])
            ->latest('start_time')->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $totalSeconds = (clone $query)->get(['start_time', 'end_time'])->sum(fn (Shift $shift) => $this->seconds($shift));

        return [
            'items' => $paginator->getCollection(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'summary' => [
                'total_hours' => round($totalSeconds / 3600, 1),
                'active_shift_id' => Shift::open()->where('user_id', $target->id)->forShop($shopId)->value('id'),
            ],
        ];
    }

    /**
     * Owner correction of a recorded shift. Edits are flagged and attributed
     * because shift records drive payroll.
     *
     * @param  array{start_time?: ?string, end_time?: ?string, reason: string}  $data
     */
    public function correct(User $actor, int $shiftId, array $data): Shift
    {
        if ($actor->user_type !== 'merchant') {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can do this', 403);
        }

        $merchant = $actor->actingMerchant() ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
        $userIds = Employee::where('shop_id', $merchant->id)->pluck('user_id')->push($actor->id);

        return DB::transaction(function () use ($shiftId, $userIds, $merchant, $data, $actor) {
            $shift = Shift::whereIn('user_id', $userIds)->forShop($merchant->id)->lockForUpdate()->find($shiftId)
                ?? throw new ApiException('shift.not_found', 'We could not find that shift', 404);

            $start = ! empty($data['start_time']) ? $this->parse($data['start_time'], 'start_time') : Carbon::parse($shift->start_time);
            $end = ! empty($data['end_time']) ? $this->parse($data['end_time'], 'end_time') : ($shift->end_time ? Carbon::parse($shift->end_time) : null);

            if ($end && $end->lt($start)) {
                throw new ApiException('shift.end_before_start', 'The end time is before the start time', 422, [], 'end_time');
            }

            $shift->update([
                'start_time' => $start->format('Y-m-d H:i:s'),
                'end_time' => $end?->format('Y-m-d H:i:s'),
                'edited_by' => $actor->id,
                'edited_at' => now(),
                'edit_reason' => $data['reason'],
            ]);

            return $shift->load(['user.employee', 'user.merchant', 'editor']);
        });
    }

    /**
     * Whose shifts are being read: the caller's own, or, with the `employees`
     * permission, any employee of the same shop.
     */
    private function targetUser(User $actor, ?int $employeeId): User
    {
        if ($employeeId === null) {
            return $actor;
        }

        if (! $actor->hasPosPermission('employees')) {
            throw new ApiException('auth.permission_denied', 'You do not have access to this', 403, ['required_permission' => 'employees']);
        }

        $merchant = $actor->actingMerchant() ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
        $employee = Employee::where('shop_id', $merchant->id)->find($employeeId)
            ?? throw new ApiException('employee.not_found', 'We could not find that staff member', 404);

        // A removed employee's login is soft-deleted, but their shift history stays readable
        return User::withTrashed()->find($employee->user_id)
            ?? throw new ApiException('employee.not_found', 'We could not find that staff member', 404);
    }

    private function shopId(User $user): int
    {
        return $user->actingMerchant()?->id
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
    }

    private function parse(string $value, string $field): Carbon
    {
        $time = Carbon::parse($value)->setTimezone(config('app.timezone'));

        if ($time->gt(now()->addMinutes(self::CLOCK_SKEW_MINUTES))) {
            throw new ApiException('shift.time_in_future', 'That time is in the future', 422, [], $field);
        }

        return $time;
    }
}
