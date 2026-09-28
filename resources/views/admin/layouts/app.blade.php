<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('titulo', 'Panel') · Comunitarios</title>
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
</head>
<body class="admin">

{{-- Primer elemento tabulable: quien navega con teclado no tiene que recorrer
     el menú entero en cada página. --}}
<a class="admin-saltar" href="#contenido">Saltar al contenido</a>

<div class="admin-shell">

    <aside class="admin-lateral">
        <div class="admin-lateral__marca">
            Comunitarios
            <span>panel</span>
        </div>

        <nav class="admin-nav" aria-label="Secciones del panel">
            {{-- Iconos en rejilla fija: los textos empiezan todos en la misma
                 columna, que es lo que hace que el menu se lea de un vistazo. --}}
            <a href="{{ route('admin.resumen') }}"
               @if(request()->routeIs('admin.resumen')) aria-current="page" @endif>
                <i data-lucide="layout-dashboard" aria-hidden="true"></i>
                <span>Resumen</span>
            </a>

            <a href="{{ route('admin.fondos.index') }}"
               @if(request()->routeIs('admin.fondos.*')) aria-current="page" @endif>
                <i data-lucide="folder-heart" aria-hidden="true"></i>
                <span>Fondos</span>
            </a>

            {{-- El contador sale en la propia navegacion porque la cola es
                 trabajo que se acumula: si no se ve al entrar, no se hace. --}}
            @php($pendientesQr = \App\Http\Controllers\Admin\VerificacionController::pendientes())
            <a href="{{ route('admin.verificacion.index') }}"
               @if(request()->routeIs('admin.verificacion.*')) aria-current="page" @endif>
                <i data-lucide="receipt-text" aria-hidden="true"></i>
                <span>
                    Verificación
                    @if ($pendientesQr > 0)
                        <span class="admin-nav__contador" aria-label="{{ $pendientesQr }} pendientes">{{ $pendientesQr }}</span>
                    @endif
                </span>
            </a>

            @can('verCualquiera', App\Models\AdminUser::class)
                <a href="{{ route('admin.usuarios.index') }}"
                   @if(request()->routeIs('admin.usuarios.*')) aria-current="page" @endif>
                    <i data-lucide="users-round" aria-hidden="true"></i>
                    <span>Administradores</span>
                </a>
            @endcan
        </nav>

        <div class="admin-lateral__pie">
            <p>
                {{ auth('admin')->user()?->username }}<br>
                <span class="estado estado--{{ auth('admin')->user()?->role->value }}">
                    {{ auth('admin')->user()?->role->etiqueta() }}
                </span>
            </p>

            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit" class="boton boton--secundario">Cerrar sesión</button>
            </form>
        </div>
    </aside>

    <main class="admin-main" id="contenido">

        <div class="admin-encabezado">
            <div>
                <h1>@yield('titulo', 'Panel')</h1>
                @hasSection('descripcion')
                    <p>@yield('descripcion')</p>
                @endif
            </div>
            @yield('acciones')
        </div>

        {{-- Los avisos van en una región viva: quien use lector de pantalla se
             entera del resultado sin tener que ir a buscarlo. --}}
        <div aria-live="polite">
            @if (session('exito'))
                <div class="admin-aviso admin-aviso--exito" role="status">
                    {{ session('exito') }}
                </div>
            @endif

            @if (session('estado'))
                <div class="admin-aviso" role="status">
                    {{ session('estado') }}
                </div>
            @endif

            {{-- Un fallo operativo que no es de validacion: por ejemplo, que
                 otra persona ya hubiera revisado el comprobante. --}}
            @if (session('error'))
                <div class="admin-aviso admin-aviso--error" role="alert">
                    {{ session('error') }}
                </div>
            @endif
        </div>

        @if ($errors->any())
            <div class="admin-aviso admin-aviso--error" role="alert" aria-live="assertive">
                <h2>Revisa estos {{ $errors->count() === 1 ? 'detalle' : 'detalles' }}</h2>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('contenido')
    </main>
</div>

{{--
    CONFIRMACIÓN DE ACCIONES DESTRUCTIVAS

    Uno solo para todo el panel. Cualquier formulario con `data-confirmar="…"`
    pasa por aquí antes de enviarse. Sustituye al `confirm()` del navegador,
    que no se puede estilar y que varios navegadores dejan silenciar con una
    casilla que el usuario marca sin querer.

    Si el navegador no soportara <dialog>, el formulario se envía sin
    preguntar: nunca se bloquea una acción por no poder mostrar el aviso.
--}}
<dialog class="admin-dialogo" data-confirmacion aria-labelledby="titulo-confirmacion">
    <form method="dialog">
        <h2 id="titulo-confirmacion">
            <i data-lucide="triangle-alert" aria-hidden="true"></i>
            ¿Seguro?
        </h2>
        <p class="admin-dialogo__resumen" data-confirmacion-texto></p>
        <div class="admin-dialogo__acciones">
            <button type="button" class="boton boton--peligro" data-confirmacion-aceptar>Sí, continuar</button>
            <button type="button" class="boton boton--secundario" data-confirmacion-cancelar>Cancelar</button>
        </div>
    </form>
</dialog>

</body>
</html>
