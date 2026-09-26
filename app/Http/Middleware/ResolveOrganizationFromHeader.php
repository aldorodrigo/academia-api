<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activa la organización indicada en el header X-Organization (slug)
 * y verifica que el usuario autenticado pertenezca a ella.
 */
class ResolveOrganizationFromHeader
{
    public const HEADER = 'X-Organization';

    public function __construct(private CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->header(self::HEADER);

        abort_if(blank($slug), Response::HTTP_BAD_REQUEST, 'Falta el header '.self::HEADER.'.');

        $organization = Organization::query()->where('slug', $slug)->first();

        abort_if(
            $organization?->isSuspended() && ! $request->user()?->is_super_admin,
            Response::HTTP_FORBIDDEN,
            'La organización está suspendida.',
        );

        abort_if(
            $organization === null || ! $request->user()?->belongsToOrganization($organization),
            Response::HTTP_FORBIDDEN,
            'No tenés acceso a esta organización.',
        );

        $this->current->set($organization);

        return $next($request);
    }
}
