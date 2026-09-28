{{--
    «+ S/ X por verificar»

    ── ESTA CIFRA NO ES RECAUDADO, Y POR ESO VA APARTE ────────────────────────

    Es dinero que llegó por Yape o Plin y que el equipo todavía no ha
    comprobado. Sumarlo al recaudado haría que el total BAJARA en cuanto un
    comprobante resultara falso, y un contador que baja destruye la confianza
    en una fundación mucho más de lo que la habría construido enseñarlo antes.

    Por eso: jerarquía menor que la cifra confirmada, etiqueta propia, y un
    texto que explique qué es. Quien lo lee tiene que entender en una frase que
    ese dinero existe pero todavía no cuenta.

    ── SI NO HAY NADA PENDIENTE, NO SALE ──────────────────────────────────────

    Nada de «S/ 0.00 por verificar». Un cero ahí solo genera la duda de si algo
    va mal.

    Parámetros:
      $monto    float   lo pendiente
      $aportes  int     cuántos aportes son
      $simbolo  string  «S/», «$»…
      $tono     string  'claro' sobre fondo oscuro; por defecto, sobre claro
--}}

@php($tono = $tono ?? 'oscuro')

@if ($monto > 0)
    <span @class(['don-pendiente', 'don-pendiente--claro' => $tono === 'claro'])
          title="Aportes recibidos por Yape o Plin que nuestro equipo está confirmando.">
        <i data-lucide="clock" aria-hidden="true"></i>
        <span>
            <strong>+ {{ $simbolo }} {{ number_format($monto, 2) }}</strong>
            por verificar
            <small>{{ $aportes }} {{ $aportes === 1 ? 'aporte recibido' : 'aportes recibidos' }} por Yape o Plin que estamos confirmando</small>
        </span>
    </span>
@endif
