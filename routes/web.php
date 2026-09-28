<?php

declare(strict_types=1);

use App\Http\Controllers\Publico\DonarController;
use App\Http\Controllers\Publico\FondoPublicoController;
use App\Http\Controllers\Publico\ResultadoDonacionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

/*
|--------------------------------------------------------------------------
| Sitio publico de donaciones
|--------------------------------------------------------------------------
|
| Todo se renderiza en el servidor. El JavaScript mejora la experiencia
| (pasos sin recarga, contadores en vivo, reconciliacion) pero ninguna de
| estas paginas depende de el para ser util.
|
*/

Route::get('/donar', DonarController::class)->name('donar');
Route::get('/donar/{fondo:slug}', DonarController::class)->name('donar.fondo');

Route::get('/fondos/{fondo:slug}', FondoPublicoController::class)->name('fondos.mostrar');

// La vuelta del checkout. Aqui arranca la red de seguridad 2.
Route::get('/donacion/resultado', ResultadoDonacionController::class)->name('donacion.resultado');
