@extends('admin.layouts.app')

@section('titulo', 'Verificación de comprobantes')
@section('descripcion', 'Aportes por Yape o Plin esperando a que alguien confirme cuánto entró de verdad.')

@section('contenido')

    {{--
        LA COLA VA POR ANTIGÜEDAD.

        Quien lleva más esperando a que le confirmen su aporte es quien peor lo
        está pasando. Ordenar por importe convertiría esto en una lista de
        prioridades donde las donaciones pequeñas no se revisarían nunca.
    --}}

    <form method="GET" class="admin-filtros" role="search">
        <div class="campo">
            <label for="filtro-estado">Estado</label>
            <select id="filtro-estado" name="estado">
                <option value="pendiente" @selected($estadoActivo?->value === 'pendiente')>Pendientes ({{ $pendientes }})</option>
                <option value="aprobado" @selected($estadoActivo?->value === 'aprobado')>Aprobadas</option>
                <option value="rechazado" @selected($estadoActivo?->value === 'rechazado')>Rechazadas</option>
                <option value="todos" @selected($estadoActivo === null)>Todas</option>
            </select>
        </div>

        <div class="campo">
            <label for="filtro-fondo">Fondo</label>
            <select id="filtro-fondo" name="fondo">
                <option value="0">Todos los fondos</option>
                @foreach ($fondos as $fondo)
                    <option value="{{ $fondo->id }}" @selected($fondoActivo === $fondo->id)>{{ $fondo->nombre }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="boton boton--secundario">Filtrar</button>
    </form>

    @if ($donaciones->isEmpty())
        <div class="admin-tarjeta admin-vacio">
            <p><strong>No hay nada esperando.</strong></p>
            <p>Cuando alguien done por Yape o Plin y suba su comprobante, aparecerá aquí.</p>
        </div>
    @else
        <div class="admin-tabla-envoltorio">
            <table class="admin-tabla">
                <caption class="admin-tabla__leyenda">
                    {{ $donaciones->total() }} {{ $donaciones->total() === 1 ? 'donación' : 'donaciones' }} en este filtro.
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Recibida</th>
                        <th scope="col">Donante</th>
                        <th scope="col">Fondo</th>
                        <th scope="col" class="admin-tabla__numero">Declarado</th>
                        <th scope="col">Medio</th>
                        <th scope="col">Comprobante</th>
                        <th scope="col">Estado</th>
                        <th scope="col"><span class="visually-hidden">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($donaciones as $donacion)
                        @php($esImagen = str_starts_with((string) $donacion->comprobante_mime, 'image/'))
                        <tr>
                            <td>
                                <time datetime="{{ $donacion->created_at?->toIso8601String() }}">
                                    {{ $donacion->created_at?->timezone(config('donaciones.zona_horaria_display'))->format('d/m/Y H:i') }}
                                </time>
                            </td>
                            <td>
                                {{-- El nombre real solo se ve aquí dentro, con sesión.
                                     Que el donante pidiera anonimato afecta a lo que se
                                     publica, no a quien tiene que verificar el pago. --}}
                                {{ $donacion->nombre }}
                                @unless ($donacion->visible_publico)
                                    <small class="admin-tabla__apunte">pidió anonimato</small>
                                @endunless
                            </td>
                            <td>{{ $donacion->fondo?->nombre ?? '—' }}</td>
                            <td class="admin-tabla__numero">
                                {{ $donacion->moneda }} {{ number_format((float) $donacion->monto_referencial, 2) }}
                                @if ($donacion->monto_real !== null)
                                    <small class="admin-tabla__apunte">
                                        real: {{ $donacion->moneda }} {{ number_format((float) $donacion->monto_real, 2) }}
                                    </small>
                                @endif
                            </td>
                            <td>
                                {{ $donacion->proveedor_pago->etiqueta() }}
                                @if ($donacion->referencia_pago)
                                    <small class="admin-tabla__apunte">op. {{ $donacion->referencia_pago }}</small>
                                @endif
                            </td>
                            <td>
                                @if ($donacion->comprobante_path)
                                    @if ($esImagen)
                                        {{-- La miniatura abre el visor. Las capturas de Yape
                                             se leen mal en pequeño y el monto es justo lo
                                             que hay que leer bien. --}}
                                        <button type="button"
                                                class="admin-miniatura"
                                                data-visor-abrir
                                                data-visor-src="{{ route('admin.verificacion.comprobante', $donacion) }}"
                                                data-visor-titulo="Comprobante de {{ $donacion->nombre }}">
                                            <img src="{{ route('admin.verificacion.comprobante', $donacion) }}"
                                                 alt="Comprobante de {{ $donacion->nombre }}"
                                                 loading="lazy">
                                            <span>Ampliar</span>
                                        </button>
                                    @else
                                        {{-- Un PDF no se amplía: se descarga. --}}
                                        <a class="boton boton--secundario"
                                           href="{{ route('admin.verificacion.comprobante', $donacion) }}"
                                           target="_blank" rel="noopener">Abrir PDF</a>
                                    @endif
                                @else
                                    <span class="admin-tabla__apunte">sin comprobante</span>
                                @endif
                            </td>
                            <td>
                                <span class="estado estado--{{ $donacion->estado->value }}">
                                    {{ $donacion->estado->etiqueta() }}
                                </span>
                                @if ($donacion->verificado_at)
                                    <small class="admin-tabla__apunte">
                                        {{ $donacion->verificadoPor?->nombre ?? $donacion->verificadoPor?->username ?? 'alguien' }},
                                        {{ $donacion->verificado_at->timezone(config('donaciones.zona_horaria_display'))->format('d/m H:i') }}
                                    </small>
                                @endif
                                @if ($donacion->motivo_rechazo)
                                    <small class="admin-tabla__apunte">{{ $donacion->motivo_rechazo }}</small>
                                @endif
                            </td>
                            <td>
                                @can('verificar', $donacion)
                                    <button type="button"
                                            class="boton"
                                            data-decidir-abrir
                                            data-decidir-id="{{ $donacion->id }}">Revisar</button>
                                @else
                                    <span class="admin-tabla__apunte">revisada</span>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $donaciones->links() }}

        {{--
            DIÁLOGOS DE DECISIÓN

            Uno por donación, con <dialog> nativo: sin librerías, con Escape y
            con el foco atrapado dentro, que es lo que da showModal() gratis.

            Van fuera de la tabla porque un <dialog> dentro de una celda hereda
            su ancho y se queda del tamaño de la columna.
        --}}
        @foreach ($donaciones as $donacion)
            @continue(! auth('admin')->user()?->can('verificar', $donacion))

            <dialog class="admin-dialogo" data-decidir="{{ $donacion->id }}"
                    aria-labelledby="titulo-decidir-{{ $donacion->id }}">
                <form method="POST" action="{{ route('admin.verificacion.verificar', $donacion) }}">
                    @csrf

                    <h2 id="titulo-decidir-{{ $donacion->id }}">Revisar el aporte de {{ $donacion->nombre }}</h2>

                    <p class="admin-dialogo__resumen">
                        Declaró <strong>{{ $donacion->moneda }} {{ number_format((float) $donacion->monto_referencial, 2) }}</strong>
                        a {{ $donacion->fondo?->nombre ?? 'un fondo' }}
                        por {{ $donacion->proveedor_pago->etiqueta() }}.
                        @if ($donacion->referencia_pago)
                            Operación {{ $donacion->referencia_pago }}.
                        @endif
                    </p>

                    <div class="campo">
                        <label for="monto-real-{{ $donacion->id }}">Monto que entró de verdad</label>
                        <span class="campo__ayuda">
                            Viene precargado con lo declarado. Compruébalo contra el comprobante
                            antes de aprobar: es justo el trabajo de esta pantalla.
                        </span>
                        <input type="number"
                               id="monto-real-{{ $donacion->id }}"
                               name="monto_real"
                               step="0.01"
                               min="0.01"
                               inputmode="decimal"
                               value="{{ number_format((float) $donacion->monto_referencial, 2, '.', '') }}"
                               data-monto-declarado="{{ number_format((float) $donacion->monto_referencial, 2, '.', '') }}">
                    </div>

                    {{-- Solo aparece cuando las dos cifras difieren. Lo muestra
                         `verificacion.js`; el servidor lo exige igualmente, así
                         que sin JavaScript el formulario se rechaza con el
                         mensaje correcto en lugar de colarse. --}}
                    <div class="admin-aviso admin-aviso--error" data-aviso-diferencia hidden>
                        <label class="admin-casilla">
                            <input type="checkbox" name="confirmo_monto" value="1">
                            <span>
                                El monto no coincide con lo declarado. Confirmo que registro
                                <strong data-diferencia-real></strong> donde se declararon
                                {{ $donacion->moneda }} {{ number_format((float) $donacion->monto_referencial, 2) }}.
                            </span>
                        </label>
                    </div>

                    <div class="campo">
                        <label for="motivo-{{ $donacion->id }}">Motivo del rechazo</label>
                        <span class="campo__ayuda">Solo si rechazas. Queda registrado.</span>
                        <input type="text"
                               id="motivo-{{ $donacion->id }}"
                               name="motivo"
                               maxlength="300"
                               placeholder="La captura no se lee / el monto no coincide con ningún movimiento">
                    </div>

                    <div class="admin-dialogo__acciones">
                        <button type="submit" name="decision" value="aprobar" class="boton boton--confirmar">
                            Aprobar y sumar al fondo
                        </button>
                        <button type="submit" name="decision" value="rechazar" class="boton boton--peligro">
                            Rechazar
                        </button>
                        <button type="button" class="boton boton--secundario" data-decidir-cerrar>Cancelar</button>
                    </div>
                </form>
            </dialog>
        @endforeach
    @endif

    {{-- Visor de comprobante: uno solo, reutilizado. --}}
    <dialog class="admin-visor" data-visor aria-labelledby="titulo-visor">
        <div class="admin-visor__barra">
            <h2 id="titulo-visor" data-visor-titulo>Comprobante</h2>
            <button type="button" class="boton boton--secundario" data-visor-cerrar>Cerrar</button>
        </div>
        {{-- La imagen se puede arrastrar cuando está ampliada: el número de
             operación suele quedar en un borde de la captura. --}}
        <div class="admin-visor__lienzo" data-visor-lienzo>
            <img src="" alt="" data-visor-imagen>
        </div>
        <p class="admin-visor__ayuda">Clic para ampliar. Arrastra para moverte. Escape para cerrar.</p>
    </dialog>

@endsection
