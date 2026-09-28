<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo', 'Donar') · Comunitarios</title>
    <meta name="description" content="@yield('descripcion', 'Apoya los proyectos de la Fundación Territorial Comunitarios.')">

    {{-- La pantalla de resultado no tiene por qué indexarse: lleva
         identificadores de operación en la URL. --}}
    @hasSection('noindex')
        <meta name="robots" content="noindex, nofollow">
    @endif

    @vite(['resources/css/app.css', 'resources/js/publico.js'])
</head>
<body class="don-pagina">

{{-- Primer elemento tabulable: quien navega con teclado no tiene que recorrer
     toda la cabecera en cada página. --}}
<a class="don-saltar" href="#contenido">Saltar al contenido</a>

<main id="contenido" class="don-contenedor">
    @yield('contenido')
</main>

@yield('despues')

</body>
</html>
