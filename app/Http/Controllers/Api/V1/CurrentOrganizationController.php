<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\AttendanceAccess;
use App\Actions\Billing\CashCollectionAccess;
use App\Actions\Billing\PaymentReportAccess;
use App\Actions\Billing\WaiveCharges;
use App\Actions\Enrollments\EnrollmentRequestAccess;
use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCanConfigureOrganization;
use App\Models\LessonProfile;
use App\Models\RoleAssignment;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentOrganizationController extends Controller
{
    /**
     * Datos y configuración de la organización activa (header X-Organization).
     */
    public function __invoke(Request $request, CurrentOrganization $current): JsonResponse
    {
        $organization = $current->get();
        $user = $request->user();

        $roles = $user->currentRoleAssignments($organization)
            ->map(fn (RoleAssignment $assignment) => [
                'name' => $assignment->role->name,
                'label' => $assignment->label(),
                'starts_on' => $assignment->starts_on?->toDateString(),
                'ends_on' => $assignment->ends_on?->toDateString(),
            ])
            ->values();

        if ($user->is_super_admin) {
            $roles->prepend(['name' => 'super_admin', 'label' => 'Super admin', 'starts_on' => null, 'ends_on' => null]);
        }

        return response()->json([
            'data' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'type' => $organization->type,
                'currency' => $organization->currency,
                'timezone' => $organization->timezone,
                'terminology' => array_merge($organization::DEFAULT_TERMINOLOGY, $organization->terminology ?? []),
                // Formas femeninas que ajustó la organización (vacío = las de la regla) y, por palabra, plural,
                // género, artículo y formas de persona; también "organization" (club, academia…). Ver PLAN_GENERO.md.
                'terminology_feminine' => (object) ($organization->terminology_feminine ?? []),
                'vocabulary' => $organization->vocabulary(),
                'features' => $organization->features ?? [],
                'membership' => [
                    'roles' => $roles,
                    // Permisos que usa la app para mostrar secciones (ej. informes).
                    'permissions' => collect([
                        'view_reports' => 'View:Reports',
                        'create_students' => 'Create:Student',
                        // Bajas y condonación (docs/PLAN_BAJAS.md).
                        'withdraw_students' => WithdrawalController::WITHDRAW_PERMISSION,
                        'waive_charges' => WaiveCharges::PERMISSION,
                    ])
                        ->filter(fn (string $permission) => $user->can($permission))
                        ->keys()
                        ->when(AttendanceAccess::canTakeAny($user), fn ($permissions) => $permissions->push('take_attendance'))
                        ->when(PaymentReportAccess::canReview($user, $organization), fn ($permissions) => $permissions->push('review_payment_reports'))
                        ->when(EnrollmentRequestAccess::canReviewAny($user), fn ($permissions) => $permissions->push('manage_enrollment_requests'))
                        ->when(CashCollectionAccess::canCollect($user), fn ($permissions) => $permissions->push('collect_payments'))
                        ->when(
                            $organization->hasFeature(Feature::PrivateLessons) && LessonProfile::teaches($user, $organization),
                            fn ($permissions) => $permissions->push('teach_lessons'),
                        )
                        ->when(
                            EnsureCanConfigureOrganization::allows($request, $current),
                            fn ($permissions) => $permissions->push('configure_organization'),
                        )
                        ->values(),
                ],
            ],
        ]);
    }
}
