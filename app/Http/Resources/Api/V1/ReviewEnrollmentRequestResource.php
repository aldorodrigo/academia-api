<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Enrollments\EnrollmentRequestAccess;
use App\Models\EnrollmentRequest;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Solicitud para quien aprueba: suma quién la pidió, la edad, si el chico ya está cargado, las categorías
 * de la disciplina con su cupo y qué se cobra del período en curso.
 *
 * @mixin EnrollmentRequest
 */
class ReviewEnrollmentRequestResource extends EnrollmentRequestResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = $this->organization->today();
        $existing = $this->isPending() ? $this->resource->existingStudent()?->load('guardians') : null;
        $birthDate = CarbonImmutable::parse($this->birth_date);

        return [
            ...parent::toArray($request),
            'requested_by' => [
                'name' => $this->user->name,
                'phone' => Phone::display($this->user->phone),
                'email' => $this->user->email,
            ],
            'age' => (int) $birthDate->diffInYears($today),
            'existing_student' => $existing ? [
                'id' => $existing->id,
                'full_name' => $existing->full_name,
                'guardians' => $existing->guardians->map(fn ($guardian) => $guardian->full_name)->values(),
            ] : null,
            'group_options' => $this->isPending()
                ? EnrollmentRequestAccess::groupOptions($this->season, $this->group->program, $birthDate)
                : [],
            'mid_period' => $this->isPending() ? EnrollmentRequestAccess::midPeriod($this->season, $this->group, $today) : null,
        ];
    }
}
