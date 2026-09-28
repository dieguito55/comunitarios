@extends('admin.layouts.app')

@section('titulo', 'Administradores')
@section('descripcion', 'Quién puede entrar al panel y qué puede hacer.')

@section('acciones')
    <a class="boton" href="{{ route('admin.usuarios.crear') }}">Nuevo administrador</a>
@endsection

@section('contenido')

    <div class="admin-aviso" role="note">
        <h2>Qué puede hacer cada rol</h2>
        <ul>
            <li><strong>Superadministrador</strong>: todo, incluido crear fondos, publicarlos y gestionar administradores.</li>
            <li><strong>Editor</strong>: edita textos e imágenes de los fondos existentes. No puede crearlos, borrarlos, publicarlos ni elegir el preseleccionado.</li>
        </ul>
        <p class="campo__ayuda">
            Publicar un fondo y decidir dónde va el dinero es una decisión de la organización,
            no de quien redacta.
        </p>
    </div>

    <div class="admin-tabla-envoltorio">
        <table class="admin-tabla">
            <thead>
                <tr>
                    <th scope="col">Usuario</th>
                    <th scope="col">Rol</th>
                    <th scope="col">Último acceso</th>
                    <th scope="col">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($usuarios as $usuario)
                    <tr>
                        <td>
                            <strong>{{ $usuario->username }}</strong>
                            @if ($yo?->is($usuario))
                                <span class="estado estado--activo">Tú</span>
                            @endif
                        </td>
                        <td>
                            <span class="estado estado--{{ $usuario->role->value }}">{{ $usuario->role->etiqueta() }}</span>
                        </td>
                        <td>{{ $usuario->last_login?->format('d/m/Y H:i') ?? 'Nunca' }}</td>
                        <td>
                            <div class="acciones">
                                <a class="boton boton--secundario" href="{{ route('admin.usuarios.editar', $usuario) }}">
                                    Editar<span class="visually-hidden"> {{ $usuario->username }}</span>
                                </a>

                                @unless ($yo?->is($usuario))
                                    <form method="POST"
                                          action="{{ route('admin.usuarios.eliminar', $usuario) }}"
                                          data-confirmar="Se va a eliminar a «{{ $usuario->username }}». Perderá el acceso al panel de inmediato.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="boton boton--peligro">
                                            Eliminar<span class="visually-hidden"> {{ $usuario->username }}</span>
                                        </button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@endsection
