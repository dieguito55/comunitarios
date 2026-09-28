<?php

declare(strict_types=1);

use App\Http\Controllers\Donaciones\CrearDonacionController;
use App\Http\Controllers\Donaciones\ReconciliarDonacionController;
use App\Http\Controllers\Publico\DashboardController;
use App\Http\Controllers\Webhooks\WebhookMercadoPagoController;
use App\Http\Middleware\ValidarFirmaMercadoPago;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Endpoints públicos de donaciones
|--------------------------------------------------------------------------
|
| Los llaman el navegador del donante y Mercado Pago, sin sesión. Los del
| donante llevan límite por IP (deuda técnica 10): el sistema de referencia no
| tenía ninguno y un bucle en el formulario podía llenar la tabla de donaciones
| con pendientes basura.
|
*/

/*
| Datos publicos del dashboard. Solo lectura, sin ningun dato personal, y
| cacheado: lo pide el navegador de cada visitante cada 30 segundos.
*/
Route::get('/dashboard', DashboardController::class)
    ->middleware('throttle:dashboard')
    ->name('dashboard');

Route::prefix('donaciones')
    ->name('donaciones.')
    ->group(function (): void {

        // Paso 1: registra la donación y devuelve el init_point del checkout.
        Route::post('/mercadopago', CrearDonacionController::class)
            ->middleware('throttle:donaciones')
            ->name('mercadopago.crear');

        /*
        | Red de seguridad 2. La llama el navegador al volver del checkout y
        | puede reintentar: el servicio es idempotente.
        */
        Route::post('/reconciliar', ReconciliarDonacionController::class)
            ->middleware('throttle:donaciones')
            ->name('reconciliar');
    });

/*
| Red de seguridad 1. La llama Mercado Pago, no una persona.
|
| SIN THROTTLE A PROPÓSITO: estrangular a Mercado Pago con un 429 haría que
| reintentara y retrasaría acreditaciones. Quien filtra aquí es la firma.
|
| GET además de POST porque MP verifica el endpoint con GET al registrarlo.
*/
Route::match(['post', 'get'], '/webhooks/mercadopago', WebhookMercadoPagoController::class)
    ->middleware(ValidarFirmaMercadoPago::class)
    ->name('webhooks.mercadopago');
