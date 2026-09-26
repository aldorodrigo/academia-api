<?php

namespace App\Http\Resources\Api\V1;

use App\Models\MedicalRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Solo se arma si el usuario puede ver la ficha médica (StudentPolicy::viewMedical).
 *
 * @mixin MedicalRecord
 */
class MedicalRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'blood_type' => $this->blood_type,
            'allergies' => $this->allergies,
            'conditions' => $this->conditions,
            'medications' => $this->medications,
            'emergency_contact' => [
                'name' => $this->emergency_contact_name,
                'phone' => $this->emergency_contact_phone,
            ],
            'fit_until' => $this->fit_until?->toDateString(),
        ];
    }
}
