<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClassReminderPreference;
use App\Models\LessonProfile;
use App\Models\NotificationSetting;
use App\Models\Student;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Avisos de días de clase del usuario: cuántos y cuándo (técnico y tutor).
 */
class NotificationSettingsController extends Controller
{
    public function show(Request $request, CurrentOrganization $current): JsonResponse
    {
        return response()->json(['data' => $this->payload($request, $current)]);
    }

    public function update(Request $request, CurrentOrganization $current): JsonResponse
    {
        $offsets = fn (string $key) => [
            "{$key}.offsets" => ['sometimes', 'array', 'min:1', 'max:'.NotificationSetting::MAX],
            "{$key}.offsets.*" => ['required', Rule::in(NotificationSetting::allowedValues())],
        ];

        $data = $request->validate([
            'instructor' => ['sometimes', 'array'],
            'instructor.enabled' => ['sometimes', 'boolean'],
            ...$offsets('instructor'),
            'guardian' => ['sometimes', 'array'],
            ...$offsets('guardian'),
        ], [
            '*.offsets.max' => 'Podés tener hasta '.NotificationSetting::MAX.' avisos por clase.',
            '*.offsets.min' => 'Elegí al menos un aviso.',
            '*.offsets.*.in' => 'Ese aviso no es una opción válida.',
        ]);

        $organization = $current->get();
        $settings = NotificationSetting::for($request->user(), $organization);

        if (isset($data['instructor']['enabled'])) {
            $settings->instructor_enabled = (bool) $data['instructor']['enabled'];
        }
        if (isset($data['instructor']['offsets'])) {
            $settings->instructor_offsets = NotificationSetting::normalize($data['instructor']['offsets']);
        }
        if (isset($data['guardian']['offsets'])) {
            $settings->guardian_offsets = NotificationSetting::normalize($data['guardian']['offsets']);
        }
        $settings->save();

        return response()->json(['data' => $this->payload($request, $current)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, CurrentOrganization $current): array
    {
        $user = $request->user();
        $organization = $current->get();
        $settings = NotificationSetting::for($user, $organization);

        $students = Student::query()->inChargeOf($user)->orderBy('birth_date')->get();
        $enabled = ClassReminderPreference::query()->where('user_id', $user->id)->where('enabled', true)->pluck('student_id')->flip();

        return [
            'instructor' => $user->instructedGroups()->exists() || LessonProfile::teaches($user, $organization) ? [
                'enabled' => (bool) $settings->instructor_enabled,
                'offsets' => $settings->instructorOffsets($organization),
            ] : null,
            'guardian' => $students->isEmpty() ? null : [
                'offsets' => $settings->guardianOffsets($organization),
                'students' => $students->map(fn (Student $student) => [
                    'id' => $student->id,
                    'first_name' => $student->first_name,
                    'enabled' => $enabled->has($student->id),
                ])->values(),
            ],
            'options' => collect(NotificationSetting::OPTIONS)
                ->map(fn (string $label, int|string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'max' => NotificationSetting::MAX,
        ];
    }
}
