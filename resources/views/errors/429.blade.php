@include('errors._error', [
    'codigo' => 'DEMASIADOS INTENTOS',
    'tono' => 'en_proceso',
    'icono' => 'clock',
    'titulo' => 'Espera un momento antes de volver a intentarlo',
    'mensaje' => 'Se hicieron muchos intentos seguidos desde esta conexión y los hemos pausado un rato. Es una medida automática y se levanta sola.',
    'detalle' => 'Prueba otra vez en un par de minutos. No se perdió ninguna donación.',
    'acciones' => [['texto' => 'Volver al inicio', 'url' => route('home')]],
    'correoContacto' => (string) config('mail.from.address', ''),
])
