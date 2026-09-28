@include('errors._error', [
    'codigo' => 'SESIÓN CADUCADA',
    'tono' => 'en_proceso',
    'icono' => 'clock',
    'titulo' => 'La página caducó mientras la rellenabas',
    'mensaje' => 'Por seguridad, los formularios expiran si pasan muchos minutos abiertos. No se envió nada y no se cobró nada.',
    'detalle' => 'Tu información sigue en el navegador: vuelve atrás, recarga la página y envíala otra vez. Si ya la habías enviado antes, revisa tu correo antes de repetirla.',
    'acciones' => [['texto' => 'Volver a donar', 'url' => route('donar'), 'icono' => 'rotate-ccw'], ['texto' => 'Ir al inicio', 'url' => route('home')]],
    'correoContacto' => (string) config('mail.from.address', ''),
])
