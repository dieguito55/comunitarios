<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Acceso al panel · Comunitarios</title>
    @vite('resources/css/admin.css')
</head>
<body class="admin">

<main class="login-pantalla">
    <div class="login-caja">
        {{-- Se reconoce la marca antes de leer nada: es la primera pantalla de
             quien administra, y llegar a un formulario desnudo en un dominio
             que pide contraseña se parece demasiado a una suplantación. --}}
        <img class="login-caja__logo"
             src="/media/logo-comunitarios-oficial.png"
             alt=""
             aria-hidden="true"
             width="52" height="52">

        <h1>Panel de Comunitarios</h1>
        <p class="subtitulo">Acceso solo para el equipo de la fundación.</p>

        <div aria-live="polite">
            @if (session('estado'))
                <div class="admin-aviso" role="status">{{ session('estado') }}</div>
            @endif
        </div>

        @if ($errors->any())
            <div class="admin-aviso admin-aviso--error" role="alert" aria-live="assertive">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.login.entrar') }}">
            @csrf

            @include('admin.partials.campo', [
                'nombre' => 'username',
                'etiqueta' => 'Nombre de usuario',
                'requerido' => true,
                'atributos' => ['autocomplete' => 'username', 'autofocus' => 'autofocus', 'maxlength' => '50'],
            ])

            @include('admin.partials.campo', [
                'nombre' => 'password',
                'etiqueta' => 'Contraseña',
                'tipo' => 'password',
                'requerido' => true,
                'atributos' => ['autocomplete' => 'current-password'],
            ])

            <button type="submit" class="boton">Entrar</button>
        </form>
    </div>
</main>

</body>
</html>
