<?php

use App\Http\Middleware\EnsureCanConfigureOrganization;
use App\Http\Middleware\ResolveOrganizationFromHeader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // En producción solo se llega a PHP-FPM por la red privada de Caddy
        // (y, en un servidor compartido, por el proxy de entrada): se confía en
        // los encabezados X-Forwarded para que la IP del cliente (límites de
        // pedidos de código) y el HTTPS de las URLs sean los reales.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'organization' => ResolveOrganizationFromHeader::class,
            'configure' => EnsureCanConfigureOrganization::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
