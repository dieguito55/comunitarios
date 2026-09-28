@extends('publico.layout')

@section('titulo', 'Donar')
@section('descripcion', 'Elige un proyecto de Comunitarios y aporta con tarjeta de forma segura.')

@section('contenido')

    <h1 class="don-titulo">
        Tu aporte tiene <span class="don-manuscrito">destino</span>
    </h1>
    <p class="don-entradilla">
        Elige a qué proyecto va tu donación. Cada sol queda registrado en el fondo que elijas
        y puedes seguir cuánto lleva recaudado.
    </p>

    @if ($fondoNoDisponible)
        <div class="don-aviso don-aviso--atencion" role="status">
            <strong>{{ $fondoNoDisponible->nombre }}</strong> ya no está recibiendo donaciones
            ({{ mb_strtolower($fondoNoDisponible->estado->etiqueta()) }}). Puedes ver lo que
            recaudó en <a href="{{ route('fondos.mostrar', $fondoNoDisponible) }}">su página</a>,
            o elegir otro proyecto abajo.
        </div>
    @endif

    @if ($fondos->isEmpty())
        <div class="don-aviso don-aviso--atencion" role="status">
            <h2>No hay campañas abiertas ahora mismo</h2>
            <p>Estamos preparando la siguiente. Vuelve pronto o escríbenos si quieres colaborar.</p>
        </div>
    @else

        {{-- El formulario entero se renderiza en el servidor: si el JavaScript
             no llega a ejecutarse, sigue siendo un formulario usable. El JS
             solo añade los pasos y el envío sin recarga. --}}
        <form data-donacion-formulario
              method="POST"
              action="{{ route('donar') }}"
              novalidate
              data-moneda="{{ $moneda }}"
              data-monto-minimo="{{ $montoMinimo }}"
              data-monto-maximo="{{ $montoMaximo }}">

            {{-- ── Paso 1: el fondo ─────────────────────────────────────── --}}
            <section class="don-paso" data-paso="1">
                <h2 class="don-paso__titulo">
                    <span class="don-paso__numero" aria-hidden="true">1</span>
                    Elige el proyecto
                </h2>

                <div class="don-aviso" data-donacion-aviso-fondo aria-live="polite"></div>

                <fieldset class="don-fondos">
                    <legend class="visually-hidden">Proyectos que están recibiendo donaciones</legend>

                    @foreach ($fondos as $fondo)
                        {{-- Radios de verdad, no divs con onclick: así funciona
                             con teclado y lo anuncia un lector de pantalla. --}}
                        <label class="don-fondo {{ $fondo->color_token->claseCss() }}"
                               data-fondo-slug="{{ $fondo->slug }}">
                            <input type="radio"
                                   name="fondo_id"
                                   value="{{ $fondo->id }}"
                                   @checked($preseleccionado?->id === $fondo->id)>

                            @if ($fondo->imagen_portada)
                                <img class="don-fondo__imagen"
                                     src="{{ Str::startsWith($fondo->imagen_portada, ['media/', 'uploads/']) ? '/' . $fondo->imagen_portada : '/uploads/fondos/' . $fondo->imagen_portada }}"
                                     alt=""
                                     loading="lazy"
                                     width="400" height="140">
                            @else
                                <span class="don-fondo__imagen" aria-hidden="true"></span>
                            @endif

                            <span class="don-fondo__cuerpo">
                                <strong class="don-fondo__nombre">{{ $fondo->nombre }}</strong>
                                <span class="don-fondo__resumen">{{ $fondo->resumen }}</span>

                                <span class="don-fondo__cifra" data-fondo-recaudado>
                                    {{ $fondo->moneda }} {{ number_format((float) $fondo->recaudado, 2) }}
                                    <small><span data-fondo-donaciones>{{ $fondo->donaciones_count }}</span> donaciones</small>
                                </span>

                                @if ($fondo->porcentajeDeMeta() !== null)
                                    <span class="don-progreso"
                                          data-fondo-progreso
                                          role="progressbar"
                                          aria-valuenow="{{ (int) $fondo->porcentajeDeMeta() }}"
                                          aria-valuemin="0"
                                          aria-valuemax="100"
                                          aria-label="Progreso de {{ $fondo->nombre }}">
                                        <span class="don-progreso__relleno"
                                              data-fondo-progreso-relleno
                                              style="width: {{ $fondo->porcentajeDeMeta() }}%"></span>
                                    </span>
                                    <span class="don-progreso__texto">
                                        {{ $fondo->porcentajeDeMeta() }}% de {{ number_format((float) $fondo->meta, 2) }}
                                    </span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                @if ($fondos->count() > 1)
                    <p>
                        <button type="button" class="don-boton don-boton--secundario" data-donacion-continuar>
                            Continuar
                        </button>
                    </p>
                @endif
            </section>

            {{-- ── Paso 2: datos y pago ─────────────────────────────────── --}}
            {{-- Con un solo fondo ya está todo decidido, así que el paso 2 se
                 muestra desde el principio. --}}
            <section class="don-paso" data-paso="2" @if($fondos->count() > 1 && ! $preseleccionado) hidden @endif>
                <h2 class="don-paso__titulo">
                    <span class="don-paso__numero" aria-hidden="true">2</span>
                    Tus datos y el monto
                </h2>

                <div class="don-aviso" data-donacion-aviso role="alert" aria-live="polite"></div>

                <div class="don-rejilla-2">
                    <div class="don-campo" data-campo="nombre">
                        <label for="donacion-nombre">Nombre completo</label>
                        <input type="text" id="donacion-nombre" name="nombre"
                               autocomplete="name" maxlength="200" required
                               aria-describedby="error-nombre">
                        <strong class="don-campo__error" id="error-nombre" data-campo-error></strong>
                    </div>

                    <div class="don-campo" data-campo="documento">
                        <label for="donacion-documento">Documento</label>
                        <span class="don-campo__ayuda" id="ayuda-documento">DNI, RUC o pasaporte.</span>
                        <input type="text" id="donacion-documento" name="documento"
                               inputmode="numeric" maxlength="20" required
                               aria-describedby="ayuda-documento error-documento">
                        <strong class="don-campo__error" id="error-documento" data-campo-error></strong>
                    </div>
                </div>

                <div class="don-rejilla-2">
                    <div class="don-campo" data-campo="correo">
                        <label for="donacion-correo">Correo electrónico</label>
                        <span class="don-campo__ayuda" id="ayuda-correo">Aquí te llega el comprobante.</span>
                        <input type="email" id="donacion-correo" name="correo"
                               autocomplete="email" maxlength="200" required
                               aria-describedby="ayuda-correo error-correo">
                        <strong class="don-campo__error" id="error-correo" data-campo-error></strong>
                    </div>

                    <div class="don-campo" data-campo="telefono">
                        <label for="donacion-telefono">Teléfono <span class="don-campo__ayuda">(opcional)</span></label>
                        <input type="tel" id="donacion-telefono" name="telefono"
                               autocomplete="tel" maxlength="30"
                               aria-describedby="error-telefono">
                        <strong class="don-campo__error" id="error-telefono" data-campo-error></strong>
                    </div>
                </div>

                <div class="don-campo" data-campo="tipo_aportante">
                    <label for="donacion-tipo">Donas como</label>
                    <select id="donacion-tipo" name="tipo_aportante" aria-describedby="error-tipo_aportante">
                        @foreach (\App\Enums\TipoAportante::cases() as $tipo)
                            <option value="{{ $tipo->value }}">{{ $tipo->etiqueta() }}</option>
                        @endforeach
                    </select>
                    <strong class="don-campo__error" id="error-tipo_aportante" data-campo-error></strong>
                </div>

                {{-- ── Monto ───────────────────────────────────────────── --}}
                <fieldset class="don-campo" data-campo="monto">
                    <legend>¿Cuánto quieres donar?</legend>

                    {{-- Los importes vienen de config/donaciones.php, no
                         incrustados aquí: cambiarlos es decisión de tesorería. --}}
                    <div class="don-montos">
                        @foreach ($montosSugeridos as $sugerido)
                            <button type="button"
                                    class="don-monto"
                                    data-monto="{{ $sugerido }}"
                                    aria-pressed="false">
                                {{ $moneda }} {{ number_format((float) $sugerido, 0) }}
                            </button>
                        @endforeach
                    </div>

                    <label for="donacion-monto">Otro monto ({{ $moneda }})</label>
                    <span class="don-campo__ayuda" id="ayuda-monto">
                        Entre {{ number_format($montoMinimo, 2) }} y {{ number_format($montoMaximo, 2) }}.
                    </span>
                    {{-- inputmode decimal: en el móvil sale el teclado numérico. --}}
                    <input type="number"
                           id="donacion-monto"
                           name="monto"
                           inputmode="decimal"
                           step="0.01"
                           min="{{ $montoMinimo }}"
                           max="{{ $montoMaximo }}"
                           required
                           aria-describedby="ayuda-monto error-monto">
                    <strong class="don-campo__error" id="error-monto" data-campo-error></strong>
                </fieldset>

                <div class="don-casilla">
                    <input type="checkbox" id="donacion-anonimo" name="anonimo" value="1">
                    <label for="donacion-anonimo">
                        Donar de forma anónima
                        <span class="don-campo__ayuda">
                            Tu nombre no aparecerá en la lista pública de donantes.
                        </span>
                    </label>
                </div>

                <div class="don-casilla" data-campo="acepta_terminos">
                    <input type="checkbox" id="donacion-terminos" name="acepta_terminos" value="1" required
                           aria-describedby="error-acepta_terminos">
                    <label for="donacion-terminos">
                        Acepto los términos y la política de privacidad
                    </label>
                </div>
                <strong class="don-campo__error" id="error-acepta_terminos" data-campo-error></strong>

                {{-- ── Pago ────────────────────────────────────────────── --}}
                <div class="don-pagos">
                    <button type="submit"
                            class="don-boton"
                            data-donacion-enviar
                            data-texto-original="Donar con tarjeta">
                        Donar con tarjeta
                    </button>

                    {{-- Hueco reservado para Yape/Plin (fase 5). Se deja
                         visible para que el diseño ya contemple dos opciones
                         de pago y no haya que recolocarlo después. --}}
                    <p class="don-pagos__pendiente">
                        El pago con Yape y Plin estará disponible muy pronto.
                    </p>
                </div>
            </section>
        </form>
    @endif

    @include('publico.componentes.dashboard')

@endsection
