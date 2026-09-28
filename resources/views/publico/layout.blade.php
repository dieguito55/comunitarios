<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Color de la barra del navegador en movil. Es el UNICO sitio del
         proyecto donde un color se escribe literal: un <meta> no puede
         referirse a una variable CSS. El valor es el de --navy-deep en
         tokens.css, y hay un test que comprueba que siguen coincidiendo. --}}
    <meta name="theme-color" content="#031d35">
    <title>@yield('titulo', 'Donar') · Comunitarios</title>
    <meta name="description" content="@yield('descripcion', 'Apoya los proyectos de la Fundación Territorial Comunitarios.')">

    {{-- La pantalla de resultado no tiene por qué indexarse: lleva
         identificadores de operación en la URL. --}}
    @hasSection('noindex')
        <meta name="robots" content="noindex, nofollow">
    @else
        <meta name="robots" content="index, follow">
    @endif

    <link rel="icon" type="image/png" sizes="100x100" href="/media/logo-comunitarios-oficial.png">
    <link rel="apple-touch-icon" href="/media/logo-comunitarios-oficial.png">

    @vite(['resources/css/app.css', 'resources/js/publico.js'])
</head>
<body class="don-pagina">

{{-- Primer elemento tabulable: quien navega con teclado no tiene que recorrer
     toda la cabecera en cada página. --}}
<a class="skip-link" href="#contenido">Saltar al contenido</a>

{{--
    LA MISMA CABECERA QUE LA PORTADA.

    Reutiliza .site-header, .brand y .desktop-nav, que ya estan definidos para
    la landing. Antes estas paginas no tenian cabecera y parecian de otro
    sitio: quien llegaba al formulario perdia de golpe la marca, la navegacion
    y la forma de volver.

    Los enlaces del menu apuntan a `/#ancla` y no a `#ancla`, porque desde
    /donar un ancla suelta no lleva a ninguna parte.
--}}
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="{{ route('home') }}" aria-label="Comunitarios, ir al inicio">
            <img class="brand-logo-image" src="/media/logo-comunitarios-oficial.png" alt="" aria-hidden="true">
            <span><strong>COMUNITARIOS</strong><small>FUNDACIÓN TERRITORIAL DE PUNO</small></span>
        </a>
        <nav class="desktop-nav" aria-label="Navegación principal">
            <a href="{{ route('home') }}#que-hacemos">Qué hacemos</a>
            <a href="{{ route('home') }}#programas">Programas</a>
            <a href="{{ route('home') }}#historias">Historias</a>
        </nav>
        @hasSection('cta-cabecera')
            @yield('cta-cabecera')
        @else
            <a class="button button-coral header-cta" href="{{ route('donar') }}">
                Donar <i data-lucide="heart-handshake"></i>
            </a>
        @endif
    </div>
</header>

{{-- Banda de cabecera de la pagina, con la misma gramatica que el hero de la
     portada pero a media altura: aqui manda el formulario. --}}
<section class="don-hero">
    <div class="container don-hero__inner">
        @hasSection('migas')
            <p class="don-migas">
                <a href="{{ route('home') }}">Inicio</a>
                <i data-lucide="chevron-right"></i>
                @yield('migas')
            </p>
        @endif

        @hasSection('etiqueta')
            <span class="pill pill-yellow">@yield('etiqueta')</span>
        @endif

        <h1>@yield('encabezado', 'Donar')</h1>

        @hasSection('entradilla')
            <p>@yield('entradilla')</p>
        @endif
    </div>
</section>

<main id="contenido" class="container don-contenedor">
    @yield('contenido')
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <a class="brand footer-brand" href="{{ route('home') }}">
            <img class="footer-brand-logo" src="/media/logo-comunitarios-oficial.png" alt="">
            <span><strong>COMUNITARIOS</strong><small>FUNDACIÓN TERRITORIAL DE PUNO</small></span>
        </a>
        <p>Articulamos personas, organizaciones y recursos para impulsar iniciativas que respondan a los desafíos de nuestro territorio.</p>
        <span></span>
        <p>© {{ date('Y') }} Comunitarios.<br>Todos los derechos reservados.</p>
        <span class="footer-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
    </div>
</footer>

@yield('despues')

</body>
</html>
