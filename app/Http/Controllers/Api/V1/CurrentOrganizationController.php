<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\AttendanceAccess;
use App\Http\Controllers\Controller;
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
                'features' => $organization->features ?? [],
                'membership' => [
                    'roles' => $roles,
                    // Permisos que usa la app para mostrar secciones (ej. informes).
                    'permissions' => collect(['view_reports' => 'View:Reports'])
                        ->filter(fn (string $permission) => $user->can($permission))
                        ->keys()
                        ->when(AttendanceAccess::canTakeAny($user), fn ($permissions) => $permissions->push('take_attendance'))
                        ->values(),
                ],
            ],
        ]);
    }
}
