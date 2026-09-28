@include('errors._error', [
    'codigo' => 'ERROR DEL SERVIDOR',
    'tono' => 'rechazado',
    'icono' => 'circle-x',
    'titulo' => 'Algo se rompió de nuestro lado',
    'mensaje' => 'No es culpa tuya ni de tu conexión. El fallo ya quedó registrado y nuestro equipo está avisado.',
    'detalle' => 'Si estabas en mitad de una donación, revisa tu correo antes de repetirla: puede que el pago sí se completara.',
    'acciones' => [['texto' => 'Volver al inicio', 'url' => route('home'), 'icono' => 'arrow-right']],
    'correoContacto' => (string) config('mail.from.address', ''),
])
