@extends('admin.layouts.app')

@section('titulo', 'Nuevo administrador')

@section('contenido')
    <section class="admin-tarjeta">
        <form method="POST" action="{{ route('admin.usuarios.guardar') }}">
            @csrf
            @include('admin.usuarios._formulario', ['usuario' => $usuario, 'roles' => $roles, 'esYo' => false])

            <div class="acciones">
                <button type="submit" class="boton">Crear administrador</button>
                <a class="boton boton--secundario" href="{{ route('admin.usuarios.index') }}">Cancelar</a>
            </div>
        </form>
    </section>
@endsection
