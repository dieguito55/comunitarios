@extends('admin.layouts.app')

@section('titulo', 'Editar: ' . $usuario->username)

@section('contenido')
    <section class="admin-tarjeta">
        <form method="POST" action="{{ route('admin.usuarios.actualizar', $usuario) }}">
            @csrf
            @method('PUT')
            @include('admin.usuarios._formulario', ['usuario' => $usuario, 'roles' => $roles, 'esYo' => $esYo])

            <div class="acciones">
                <button type="submit" class="boton">Guardar</button>
                <a class="boton boton--secundario" href="{{ route('admin.usuarios.index') }}">Cancelar</a>
            </div>
        </form>
    </section>
@endsection
