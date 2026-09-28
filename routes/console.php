<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|------------------------------------------------------------------------------
| Tareas programadas
|------------------------------------------------------------------------------
|
| Las dispara el cron del servidor con `php artisan schedule:run` cada minuto.
| Ver docs/despliegue-cpanel.md, paso 12.
|
*/

/*
| Detección diaria de desvíos en los contadores de los fondos.
|
| Va con --dry-run A PROPÓSITO: solo informa, no repara. Un recálculo
| automático cada noche dejaría los números cuadrados y haría INVISIBLE el bug
| que los descuadró. Si este informe empieza a salir con diferencias, lo que
| hay que arreglar es quien mueve los contadores, no ejecutar esto más a
| menudo.
|
| La reparación se lanza a mano, y solo después de mirar el informe:
|   php artisan fondos:recalcular
*/
Schedule::command('fondos:recalcular --dry-run')
    ->dailyAt('03:30')
    ->timezone(config('donaciones.zona_horaria_display', 'America/Lima'))
    ->appendOutputTo(storage_path('logs/conciliacion.log'))
    ->withoutOverlapping();
