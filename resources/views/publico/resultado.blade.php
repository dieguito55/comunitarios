@extends('publico.layout')

@section('titulo', 'Resultado de tu donación')
@section('noindex', true)

@section('etiqueta', 'ESTADO DE TU APORTE')
@section('encabezado', 'Gracias por dar el paso')

@section('cta-cabecera')
    <a class="button button-coral header-cta" href="{{ route('home') }}">
        Al inicio <i data-lucide="arrow-right"></i>
    </a>
@endsection

@section('contenido')

    {{--
        SIN JAVASCRIPT ESTA PÁGINA YA DICE ALGO ÚTIL.

        Lo que se pinta aquí es un mensaje PROVISIONAL deducido del `?donacion=`
        que Mercado Pago añade a la URL de retorno. No es el estado real: esa
        URL la puede escribir cualquiera. El módulo resultado.js pregunta al
        servidor —que consulta a Mercado Pago con nuestro token— y sustituye
        este texto por el veredicto de verdad.

        Por eso el texto provisional nunca afirma que el pago se completó: dice
        que se está confirmando.
    --}}

    @php
        $iconos = [
            'aprobado' => 'loader-circle',
            'rechazado' => 'circle-x',
            'en_proceso' => 'clock',
            'desconocido' => 'search',
        ];
    @endphp

    <section @class([
                 'don-resultado',
                 'don-resultado--'.$estadoProvisional => $estadoProvisional !== 'desconocido',
                 'don-resultado--esperando' => $estadoProvisional !== 'rechazado',
             ])
             data-resultado
             data-contacto="{{ $correoContacto }}">

        <div class="don-resultado__orbe" data-resultado-icono aria-hidden="true">
            <i data-lucide="{{ $iconos[$estadoProvisional] ?? 'search' }}"></i>
        </div>

        <span data-resultado-estado
              @class(['estado', 'estado--en_proceso' => $estadoProvisional === 'en_proceso'])
              @if ($estadoProvisional === 'desconocido' || $estadoProvisional === 'aprobado') hidden @endif>
            @switch($estadoProvisional)
                @case('rechazado') Rechazado @break
                @case('en_proceso') En proceso @break
                @default
            @endswitch
        </span>

        <h1 data-resultado-titulo>
            @switch($estadoProvisional)
                @case('aprobado')
                    Estamos confirmando tu donación
                    @break
                @case('rechazado')
                    El pago no se completó
                    @break
                @case('en_proceso')
                    Estamos confirmando tu pago
                    @break
                @default
                    Comprobando tu donación
            @endswitch
        </h1>

        <p data-resultado-cuerpo>
            @switch($estadoProvisional)
                @case('aprobado')
                    Volviste del pago correctamente. Estamos verificándolo con Mercado Pago.
                    El comprobante del pago lo emite y lo envía Mercado Pago.
                    @break
                @case('rechazado')
                    Mercado Pago rechazó la operación, normalmente por un problema con la tarjeta
                    o con los datos. No se te cobró nada; puedes intentarlo otra vez.
                    @break
                @case('en_proceso')
                    Algunos medios de pago tardan en acreditarse. Mercado Pago te avisará en
                    cuanto la operación termine de procesarse. No hace falta que vuelvas a donar.
                    @break
                @default
                    Estamos comprobando el estado de tu operación con Mercado Pago.
            @endswitch
        </p>

        {{-- La segunda linea: el matiz que responde a «¿y ahora que?». La
             rellena `resultado.js` segun el desenlace real. --}}
        <p class="don-resultado__detalle" data-resultado-detalle hidden></p>

        {{--
            ESTADO DE CARGA HONESTO.

            Mientras `resultado.js` pregunta al servidor, se ven dos barras de
            esqueleto en vez de una pantalla quieta que parece colgada. Nace
            oculto: si el JavaScript no se ejecuta, no hay nada que esperar y
            el mensaje de arriba es la respuesta final.
        --}}
        <div data-resultado-cargando hidden aria-hidden="true">
            <span class="don-esqueleto"></span>
            <span class="don-esqueleto"></span>
        </div>

        {{--
            LAS SALIDAS

            Las pinta `resultado.js` segun el estado REAL, que el servidor no
            conoce al renderizar: aqui solo tiene el `?donacion=` de la URL, que
            no decide nada.

            Sin JavaScript se quedan estas dos, que valen para cualquier caso:
            nadie puede acabar en esta pantalla sin saber a donde ir.
        --}}
        <div class="don-resultado__acciones" data-resultado-acciones>
            <a class="button button-ghost" href="{{ route('donar') }}">
                Ver los proyectos
            </a>
            <a class="button button-ghost" href="{{ route('home') }}">
                Volver al inicio
            </a>
        </div>

        <p class="don-campo__ayuda">
            @if ($correoContacto)
                ¿Algo no cuadra? Escríbenos a {{ $correoContacto }} y lo revisamos.
            @else
                ¿Algo no cuadra? Escríbenos y lo revisamos.
            @endif
        </p>
    </section>

@endsection
