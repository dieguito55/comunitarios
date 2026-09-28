<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\EstadoFondoController;
use App\Http\Controllers\Admin\FondoController;
use App\Http\Controllers\Admin\MedioFondoController;
use App\Http\Controllers\Admin\PredeterminadoFondoController;
use App\Http\Controllers\Admin\ResumenController;
use App\Http\Controllers\Admin\SesionController;
use App\Http\Controllers\Admin\UsuarioAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Panel de administración
|--------------------------------------------------------------------------
|
| Todo este archivo va dentro del grupo `web`, así que TODAS las rutas llevan
| sesión y CSRF. No es negociable: desde aquí se publica un fondo y se decide
| dónde va el dinero.
|
| El guard es `admin`, separado del `web` por defecto de Laravel (que este
| proyecto no usa). `auth:admin` además fija ese guard como el activo, lo que
| hace que las Policies reciban al AdminUser sin tener que pasarlo a mano.
|
*/

// ── Público: solo el formulario de acceso ────────────────────────────────────
Route::middleware('guest:admin')->group(function (): void {
    Route::get('/login', [SesionController::class, 'mostrar'])->name('login');

    // El límite por IP y por usuario vive dentro del controlador: necesita
    // saber QUÉ usuario se intentó para poder contar por los dos lados.
    Route::post('/login', [SesionController::class, 'entrar'])->name('login.entrar');
});

Route::post('/logout', [SesionController::class, 'salir'])
    ->middleware('auth:admin')
    ->name('logout');

// ── Panel ────────────────────────────────────────────────────────────────────
Route::middleware('auth:admin')->group(function (): void {

    Route::get('/', ResumenController::class)->name('resumen');

    // Fondos
    Route::get('/fondos', [FondoController::class, 'index'])->name('fondos.index');
    Route::get('/fondos/crear', [FondoController::class, 'crear'])->name('fondos.crear');
    Route::post('/fondos', [FondoController::class, 'guardar'])->name('fondos.guardar');
    Route::get('/fondos/{fondo}/editar', [FondoController::class, 'editar'])->name('fondos.editar');
    Route::put('/fondos/{fondo}', [FondoController::class, 'actualizar'])->name('fondos.actualizar');
    Route::delete('/fondos/{fondo}', [FondoController::class, 'eliminar'])->name('fondos.eliminar');

    // Publicar / pausar / cerrar. Solo superadmin (FondoPolicy).
    Route::post('/fondos/{fondo}/estado', EstadoFondoController::class)->name('fondos.estado');
    Route::post('/fondos/{fondo}/predeterminado', PredeterminadoFondoController::class)->name('fondos.predeterminado');

    // Galería
    Route::post('/fondos/{fondo}/medios', [MedioFondoController::class, 'guardar'])->name('fondos.medios.guardar');
    Route::delete('/fondos/{fondo}/medios/{medio}', [MedioFondoController::class, 'eliminar'])->name('fondos.medios.eliminar');
    Route::post('/fondos/{fondo}/medios/orden', [MedioFondoController::class, 'reordenar'])->name('fondos.medios.reordenar');

    // Administradores. Solo superadmin (AdminUserPolicy).
    Route::get('/usuarios', [UsuarioAdminController::class, 'index'])->name('usuarios.index');
    Route::get('/usuarios/crear', [UsuarioAdminController::class, 'crear'])->name('usuarios.crear');
    Route::post('/usuarios', [UsuarioAdminController::class, 'guardar'])->name('usuarios.guardar');
    Route::get('/usuarios/{usuario}/editar', [UsuarioAdminController::class, 'editar'])->name('usuarios.editar');
    Route::put('/usuarios/{usuario}', [UsuarioAdminController::class, 'actualizar'])->name('usuarios.actualizar');
    Route::delete('/usuarios/{usuario}', [UsuarioAdminController::class, 'eliminar'])->name('usuarios.eliminar');
});
