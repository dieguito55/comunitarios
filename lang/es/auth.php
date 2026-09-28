<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mensajes de autenticación
|--------------------------------------------------------------------------
|
| `failed` es DELIBERADAMENTE ambiguo: no dice si falló el usuario o la
| contraseña. Distinguirlos permitiría averiguar qué usuarios existen.
|
*/

return [
    'failed' => 'Usuario o contraseña incorrectos.',
    'password' => 'La contraseña es incorrecta.',
    'throttle' => 'Demasiados intentos. Vuelve a probar en :seconds segundos.',
];
