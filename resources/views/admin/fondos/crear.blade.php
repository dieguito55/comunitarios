@extends('admin.layouts.app')

@section('titulo', 'Nuevo fondo')
@section('descripcion', 'Se crea como borrador: no recibe dinero hasta que lo publiques.')

@section('acciones')
    <a class="boton boton--secundario" href="{{ route('admin.fondos.index') }}">Volver al listado</a>
@endsection

@section('contenido')

    <section class="admin-tarjeta">
        <form method="POST" action="{{ route('admin.fondos.guardar') }}" enctype="multipart/form-data">
            @csrf

            @include('admin.fondos._formulario', [
                'fondo' => $fondo,
                'colores' => $colores,
                'tieneDonaciones' => false,
            ])

            <div class="acciones">
                <button type="submit" class="boton">Crear como borrador</button>
                <a class="boton boton--secundario" href="{{ route('admin.fondos.index') }}">Cancelar</a>
            </div>
        </form>
    </section>

@endsection
