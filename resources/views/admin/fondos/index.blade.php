@extends('admin.layouts.app')

@section('titulo', 'Fondos')
@section('descripcion', 'Los proyectos a los que se puede donar.')

@section('acciones')
    @can('crear', App\Models\Fondo::class)
        <a class="boton" href="{{ route('admin.fondos.crear') }}">Nuevo fondo</a>
    @endcan
@endsection

@section('contenido')

    @if ($fondos->isEmpty())
        <div class="admin-tarjeta">
            <p>Todavía no hay ningún fondo.</p>
        </div>
    @else
        <div class="admin-tabla-envoltorio">
            <table class="admin-tabla">
                <caption class="campo__ayuda">
                    Ordenados por el campo «orden»: el número más bajo aparece antes.
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Orden</th>
                        <th scope="col">Fondo</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Recaudado</th>
                        <th scope="col">Donaciones</th>
                        <th scope="col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fondos as $fondo)
                        <tr>
                            <td>{{ $fondo->orden }}</td>
                            <td>
                                <span class="fondo-muestra {{ $fondo->color_token->claseCss() }}" aria-hidden="true"></span>
                                <strong>{{ $fondo->nombre }}</strong><br>
                                <span class="campo__ayuda">/{{ $fondo->slug }}</span>
                                @if ($fondo->es_predeterminado)
                                    <span class="estado estado--activo">Preseleccionado</span>
                                @endif
                            </td>
                            <td>
                                <span class="estado estado--{{ $fondo->estado->value }}">{{ $fondo->estado->etiqueta() }}</span>
                            </td>
                            <td>{{ number_format((float) $fondo->recaudado, 2) }} {{ $fondo->moneda }}</td>
                            <td>{{ $fondo->donaciones_count }}</td>
                            <td>
                                <a class="boton boton--secundario" href="{{ route('admin.fondos.editar', $fondo) }}">
                                    Editar<span class="visually-hidden"> {{ $fondo->nombre }}</span>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection
