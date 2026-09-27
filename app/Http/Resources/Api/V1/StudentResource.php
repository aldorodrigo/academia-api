<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\GuardianRelationship;
use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Alumno a cargo del usuario. La ficha (detailed) agrega documento, tutores,
 * horarios y la ficha médica cuando el usuario puede verla.
 *
 * @mixin Student
 */
class StudentResource extends JsonResource
{
    private bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        $data = [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'photo_url' => $this->photoUrl(),
            'is_self' => $this->user_id !== null && $this->user_id === $user->id,
            'enrollments' => $this->currentEnrollments
                ->map(fn ($enrollment) => (new EnrollmentResource($enrollment))->detailed($this->detailed)->toArray($request))
                ->values(),
        ];

        if (! $this->detailed) {
            return $data;
        }

        $canViewMedical = $user->can('viewMedical', $this->resource);

        return [
            ...$data,
            'document' => $this->document,
            'shirt_size' => $this->shirt_size,
            'position' => $this->position,
            'guardians' => $this->guardians->map(fn (Guardian $guardian) => [
                'name' => $guardian->full_name,
                'relationship' => $guardian->pivot->relationship
                    ? GuardianRelationship::parse($guardian->pivot->relationship)->label()
                    : null,
                'is_me' => $guardian->user_id !== null && $guardian->user_id === $user->id,
            ])->values(),
            'medical' => $canViewMedical && $this->medicalRecord
                ? (new MedicalRecordResource($this->medicalRecord))->toArray($request)
                : null,
            'permissions' => ['view_medical' => $canViewMedical],
        ];
    }
}
