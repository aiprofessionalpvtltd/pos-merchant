<?php

namespace App\Http\Resources\API\V1;

use App\Models\Shift;
use App\Services\ShiftService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Expects the shift loaded with `user.employee`, `user.merchant` and `editor`, so
 * a list needs no query per row.
 *
 * @property Shift $resource
 */
class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shift = $this->resource;
        $isActive = $shift->end_time === null;

        $data = [
            'id' => $shift->id,
            'employee' => $this->person($shift),
            'date' => Carbon::parse($shift->start_time)->toDateString(),
            'start_time' => ApiResponse::iso($shift->start_time),
            'end_time' => $shift->end_time ? ApiResponse::iso($shift->end_time) : null,
            // An open shift runs to the server's clock, so a wrong phone clock cannot skew the timer
            'duration_seconds' => app(ShiftService::class)->seconds($shift),
            'is_active' => $isActive,
            'edited' => $shift->edited_at !== null,
        ];

        if ($shift->edited_at) {
            $data['edited_by'] = $shift->editor ? ['id' => $shift->editor->id, 'name' => $shift->editor->name] : null;
            $data['edited_at'] = ApiResponse::iso($shift->edited_at);
            $data['edit_reason'] = $shift->edit_reason;
        }

        return $data;
    }

    /**
     * @return array{id: ?int, name: string, short_name: string}
     */
    private function person(Shift $shift): array
    {
        $user = $shift->user;
        $employee = $user?->employee?->exists ? $user->employee : null;
        $merchant = $user?->merchant?->exists ? $user->merchant : null;

        if ($employee) {
            $name = trim($employee->first_name.' '.$employee->last_name);
        } else {
            $ownerName = trim(($merchant?->first_name ?? '').' '.($merchant?->last_name ?? ''));
            $name = $ownerName !== '' ? $ownerName : ($user?->name ?? '');
        }

        return [
            'id' => $employee?->id,
            'name' => $name,
            'short_name' => collect(explode(' ', $name))->filter()->map(fn ($word) => Str::upper(Str::substr($word, 0, 1)))->take(2)->implode(''),
        ];
    }
}
