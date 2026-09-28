@include('errors._error', [
    'codigo' => 'ERROR 404',
    'tono' => 'rechazado',
    'icono' => 'search-x',
    'titulo' => 'No encontramos esta página',
    'mensaje' => 'La dirección no existe o el proyecto que buscabas ya no está publicado. Puede que el enlace estuviera mal copiado, o que la campaña se haya cerrado.',

    'acciones' => [['texto' => 'Ver los proyectos', 'url' => route('donar'), 'icono' => 'arrow-right'], ['texto' => 'Volver al inicio', 'url' => route('home')]],

])
