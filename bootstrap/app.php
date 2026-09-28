<?php

use App\Http\Middleware\ValidarFirmaMercadoPago;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        // El panel entero, bajo /admin. Va dentro del grupo `web` para que
        // todas sus rutas lleven sesion y CSRF: desde ahi se publica un fondo
        // y se decide donde va el dinero.
        then: function (): void {
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(static fn (): string => route('admin.login'));
        $middleware->alias([
            'mp.firma' => ValidarFirmaMercadoPago::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // El límite por IP lo aplica un middleware, así que su respuesta no pasa
        // por el controlador. Sin esto, un 429 devolvería {"message": ...} y
        // rompería el único contrato de error del endpoint ({"success", "error"}).
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => 'Demasiados intentos seguidos. Espera un momento y vuelve a intentarlo.',
            ], 429, array_intersect_key($e->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Reset'])));
        });
    })->create();
