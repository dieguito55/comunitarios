@extends('admin.layouts.app')

@section('titulo', 'Editar: ' . $fondo->nombre)
@section('descripcion', 'Textos, imágenes y estado de este fondo.')

@section('acciones')
    <div class="acciones">
        <a class="boton boton--secundario" href="{{ route('admin.fondos.index') }}">Volver al listado</a>
    </div>
@endsection

@section('contenido')

    <div class="admin-tarjeta">
        <p>
            <span class="fondo-muestra {{ $fondo->color_token->claseCss() }}" aria-hidden="true"></span>
            <span class="estado estado--{{ $fondo->estado->value }}">{{ $fondo->estado->etiqueta() }}</span>
            @if ($fondo->es_predeterminado)
                <span class="estado estado--activo">Preseleccionado</span>
            @endif
        </p>

        <p class="admin-cifra">
            {{ number_format($metricas['recaudado'], 2) }}
            <small>
                {{ $metricas['moneda'] }} · {{ $metricas['donaciones'] }}
                {{ $metricas['donaciones'] === 1 ? 'donación' : 'donaciones' }}
                · {{ $metricas['donantes_unicos'] }}
                {{ $metricas['donantes_unicos'] === 1 ? 'donante' : 'donantes' }}
            </small>
        </p>

        @unless ($fondo->estado->aceptaDonaciones())
            <p class="campo__ayuda">
                Este fondo <strong>no está recibiendo donaciones</strong> ahora mismo.
            </p>
        @endunless
    </div>

    {{-- ── Datos ───────────────────────────────────────────────────────── --}}
    <section class="admin-tarjeta" aria-labelledby="titulo-datos">
        <h2 id="titulo-datos">Datos del fondo</h2>

        <form method="POST"
              action="{{ route('admin.fondos.actualizar', $fondo) }}"
              enctype="multipart/form-data">
            @csrf
            @method('PUT')

            @include('admin.fondos._formulario', [
                'fondo' => $fondo,
                'colores' => $colores,
                'tieneDonaciones' => $tieneDonaciones,
            ])

            {{-- El slug deshabilitado no se envía; se manda el actual para que
                 la validación de unicidad siga teniendo con qué comparar. --}}
            @if ($tieneDonaciones)
                <input type="hidden" name="slug" value="{{ $fondo->slug }}">
            @endif

            @if ($fondo->imagen_portada)
                <div class="portada-previa">
                    <p class="campo__ayuda">Portada actual:</p>
                    <img src="{{ $imagenes->url($fondo->imagen_portada) }}"
                         alt="Portada actual de {{ $fondo->nombre }}"
                         width="600">
                </div>
            @endif

            <div class="acciones">
                <button type="submit" class="boton">Guardar cambios</button>
            </div>
        </form>
    </section>

    {{-- ── Publicación ─────────────────────────────────────────────────── --}}
    @can('cambiarEstado', $fondo)
        <section class="admin-tarjeta" aria-labelledby="titulo-estado">
            <h2 id="titulo-estado">Publicación</h2>

            <p class="campo__ayuda">
                Publicar un fondo es el momento en que empieza a recibir dinero real.
                Por eso hace falta escribir su nombre para confirmarlo.
            </p>

            <form method="POST" action="{{ route('admin.fondos.estado', $fondo) }}">
                @csrf

                <div class="campo @error('estado') campo--error @enderror">
                    <label for="campo-estado">Nuevo estado</label>
                    <select id="campo-estado"
                            name="estado"
                            @error('estado') aria-invalid="true" aria-describedby="campo-estado-error" @enderror>
                        @foreach ($estados as $estado)
                            <option value="{{ $estado->value }}" @selected(old('estado', $fondo->estado->value) === $estado->value)>
                                {{ $estado->etiqueta() }}
                                @unless ($estado->aceptaDonaciones()) — no recibe donaciones @endunless
                            </option>
                        @endforeach
                    </select>
                    @error('estado')
                        <strong class="campo__error" id="campo-estado-error">{{ $message }}</strong>
                    @enderror
                </div>

                @include('admin.partials.campo', [
                    'nombre' => 'confirmacion',
                    'etiqueta' => 'Confirmación',
                    'ayuda' => 'Solo para PUBLICAR: escribe «' . $fondo->nombre . '» exactamente. Para los demás estados, déjalo vacío.',
                    'atributos' => ['autocomplete' => 'off', 'maxlength' => '200'],
                ])

                <div class="acciones">
                    <button type="submit" class="boton boton--confirmar">Cambiar estado</button>
                </div>
            </form>
        </section>
    @endcan

    {{-- ── Preselección y borrado ──────────────────────────────────────── --}}
    @canany(['marcarPredeterminado', 'eliminar'], $fondo)
        <section class="admin-tarjeta" aria-labelledby="titulo-otras">
            <h2 id="titulo-otras">Otras acciones</h2>

            <div class="acciones">
                @can('marcarPredeterminado', $fondo)
                    @unless ($fondo->es_predeterminado)
                        <form method="POST" action="{{ route('admin.fondos.predeterminado', $fondo) }}">
                            @csrf
                            <button type="submit" class="boton boton--secundario">
                                Preseleccionar en el formulario
                            </button>
                        </form>
                    @endunless
                @endcan

                @can('eliminar', $fondo)
                    <form method="POST"
                          action="{{ route('admin.fondos.eliminar', $fondo) }}"
                          data-confirmar="Se va a borrar «{{ $fondo->nombre }}» y sus imágenes. Esta acción no se puede deshacer.">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="boton boton--peligro">Borrar este borrador</button>
                    </form>
                @else
                    <p class="campo__ayuda">
                        Este fondo no se puede borrar: solo se elimina un borrador que nunca recibió
                        donaciones. Un fondo con historial se <strong>cierra</strong>, porque su rastro
                        es rendición de cuentas.
                    </p>
                @endcan
            </div>
        </section>
    @endcanany

    {{-- ── Galería ─────────────────────────────────────────────────────── --}}
    <section class="admin-tarjeta" aria-labelledby="titulo-galeria">
        <h2 id="titulo-galeria">Galería</h2>

        @if ($fondo->medios->isEmpty())
            <p class="campo__ayuda">Todavía no hay imágenes en la galería.</p>
        @else
            <form method="POST" action="{{ route('admin.fondos.medios.reordenar', $fondo) }}">
                @csrf
                <div class="galeria">
                    @foreach ($fondo->medios as $medio)
                        <figure>
                            <img src="{{ $imagenes->url($medio->ruta) }}"
                                 alt="{{ $medio->alt ?: 'Imagen de ' . $fondo->nombre }}">
                            <figcaption>
                                <label for="orden-{{ $medio->id }}">Orden</label>
                                <input type="number"
                                       id="orden-{{ $medio->id }}"
                                       name="orden[{{ $medio->id }}]"
                                       value="{{ $medio->orden }}"
                                       min="0"
                                       step="1">
                            </figcaption>
                        </figure>
                    @endforeach
                </div>

                <div class="acciones">
                    <button type="submit" class="boton boton--secundario">Guardar el orden</button>
                </div>
            </form>

            <div class="acciones">
                @foreach ($fondo->medios as $medio)
                    <form method="POST" action="{{ route('admin.fondos.medios.eliminar', [$fondo, $medio]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="boton boton--peligro">
                            Quitar imagen {{ $loop->iteration }}
                        </button>
                    </form>
                @endforeach
            </div>
        @endif

        <form method="POST"
              action="{{ route('admin.fondos.medios.guardar', $fondo) }}"
              enctype="multipart/form-data">
            @csrf

            @include('admin.partials.campo', [
                'nombre' => 'medio',
                'etiqueta' => 'Añadir una imagen',
                'tipo' => 'file',
                'requerido' => true,
                'ayuda' => 'JPG, PNG o WEBP. Máximo 4 MB.',
                'atributos' => ['accept' => 'image/jpeg,image/png,image/webp'],
            ])

            @include('admin.partials.campo', [
                'nombre' => 'alt',
                'etiqueta' => 'Texto alternativo',
                'ayuda' => 'Describe la imagen para quien no puede verla.',
                'atributos' => ['maxlength' => '200'],
            ])

            <div class="acciones">
                <button type="submit" class="boton">Añadir a la galería</button>
            </div>
        </form>
    </section>

@endsection
