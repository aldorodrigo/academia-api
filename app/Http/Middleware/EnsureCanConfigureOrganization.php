<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guía "Primeros pasos" y configuración desde la app: solo el administrador de la
 * organización activa (o un super admin). Va después de `organization`.
 */
class EnsureCanConfigureOrganization
{
    public function __construct(private CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            self::allows($request, $this->current),
            Response::HTTP_FORBIDDEN,
            'Solo los administradores configuran el club.',
        );

        return $next($request);
    }

    public static function allows(Request $request, CurrentOrganization $current): bool
    {
        $user = $request->user();
        $organization = $current->get();

        return $user !== null && $organization !== null
            && ($user->is_super_admin || $user->isOrganizationAdmin($organization));
    }
}
