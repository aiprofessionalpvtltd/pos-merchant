<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => showDate($this->created_at),
            'start_time' => showTime($this->start_time),
            'end_time' => $this->end_time ? showTime($this->end_time) : NULL,
            'username' => $this->user->name,
            'initial' => $this->getInitials($this->user->name),
        ];
    }

    private function getInitials($name)
    {
        // Explode the name into words
        $words = explode(' ', $name);

        // Get the first character of each word
        $initials = '';
        foreach ($words as $word) {
            if (!empty($word)) {
                $initials .= strtoupper($word[0]);
            }
        }

        return $initials;
    }
}
