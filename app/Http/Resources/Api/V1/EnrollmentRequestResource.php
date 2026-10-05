<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Enrollments\EnrollmentRequestAccess;
use App\Models\EnrollmentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Solicitud de inscripción, como la ve el tutor que la pidió (la ficha médica no se devuelve).
 *
 * @mixin EnrollmentRequest
 */
class EnrollmentRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'child' => [
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'full_name' => $this->resource->fullName(),
                'birth_date' => $this->birth_date->toDateString(),
                'document' => $this->document,
            ],
            'relationship' => $this->relationship->value,
            'has_medical' => $this->medical !== null || ($this->status->value === 'aprobada' && $this->student?->medicalRecord !== null),
            'notes' => $this->notes,
            'season' => EnrollmentRequestAccess::season($this->season),
            'group' => [
                'id' => $this->group->id,
                'name' => $this->group->name,
                'program' => ['id' => $this->group->program->id, 'name' => $this->group->program->name],
            ],
            'rejection_reason' => $this->rejection_reason,
            // Null si se archivó (solicitud rechazada o cancelada).
            'student_id' => $this->student?->id,
            'created_at' => $this->created_at->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            // Quién la confirmó o la rechazó (el mismo tutor si se confirmó sola).
            'reviewed_by' => $this->reviewedBy?->name,
            'self_approved' => $this->reviewed_by !== null && $this->reviewed_by === $this->user_id,
        ];
    }
}
