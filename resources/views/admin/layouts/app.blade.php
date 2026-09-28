<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('titulo', 'Panel') · Comunitarios</title>
    @vite('resources/css/admin.css')
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
            <a href="{{ route('admin.resumen') }}"
               @if(request()->routeIs('admin.resumen')) aria-current="page" @endif>Resumen</a>

            <a href="{{ route('admin.fondos.index') }}"
               @if(request()->routeIs('admin.fondos.*')) aria-current="page" @endif>Fondos</a>

            @can('verCualquiera', App\Models\AdminUser::class)
                <a href="{{ route('admin.usuarios.index') }}"
                   @if(request()->routeIs('admin.usuarios.*')) aria-current="page" @endif>Administradores</a>
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

</body>
</html>
