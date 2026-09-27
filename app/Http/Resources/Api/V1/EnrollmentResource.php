<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Enrollment;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Enrollment
 */
class EnrollmentResource extends JsonResource
{
    private bool $detailed = false;

    public function detailed(bool $detailed = true): static
    {
        $this->detailed = $detailed;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $group = $this->group;

        $groupData = [
            'id' => $group->id,
            'name' => $group->name,
            'program' => ['id' => $group->program->id, 'name' => $group->program->name],
        ];

        if ($this->detailed) {
            $groupData['schedules'] = $group->schedules->map(fn (Schedule $schedule) => [
                'weekday' => $schedule->weekday,
                'starts_at' => Schedule::time($schedule->starts_at),
                'ends_at' => Schedule::time($schedule->ends_at),
                'venue' => $schedule->venue ? ['name' => $schedule->venue->name] : null,
            ])->values();
            $groupData['instructors'] = $group->instructors
                ->map(fn (User $instructor) => ['name' => $instructor->name])
                ->values();
        }

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'season' => ['id' => $this->season->id, 'name' => $this->season->name],
            'group' => $groupData,
        ];
    }
}
