<?php

declare(strict_types=1);

namespace App\Services\Publico;

use App\Models\Donacion;
use App\Models\Fondo;
use App\Services\Donaciones\CalcularPendientes;
use App\Services\Fondos\CalcularMetricasFondo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Los datos públicos del dashboard.
 *
 * TRES REGLAS, y son de privacidad, no de rendimiento:
 *
 *  1. **No sale ni un dato personal identificable.** Ni correo, ni documento,
 *     ni teléfono, ni IP. Nunca. El nombre solo si el donante dijo que sí.
 *
 *  2. **El anonimato se resuelve AQUÍ, en el servidor.** Cuando
 *     `visible_publico` es false, el nombre real NO VIAJA al navegador, ni
 *     siquiera para que el front lo esconda: lo que no sale del servidor no se
 *     puede leer abriendo las herramientas del navegador.
 *
 *  3. **Se cachea.** Es una agregación sobre toda la tabla de donaciones que se
 *     pide en cada visita a la portada, y encima el navegador la refresca sola
 *     cada 30 segundos.
 *
 * No hay comodín ni redistribución como en el sistema de referencia: aquí se
 * agrupa por fondo y punto. Cada sol tiene un destino concreto desde el
 * primer día.
 */
final class ConstruirDashboard
{
    public function __construct(
        private readonly CalcularMetricasFondo $metricas,
        private readonly CalcularPendientes $pendientes,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return Cache::remember(
            $this->claveDeCache(),
            (int) config('donaciones.cache_dashboard_segundos', 30),
            fn (): array => $this->calcular()
        );
    }

    /** Para que el panel o un test puedan forzar el recálculo. */
    public function olvidarCache(): void
    {
        Cache::forget($this->claveDeCache());
    }

    /** @return array<string, mixed> */
    private function calcular(): array
    {
        $fondos = Fondo::query()
            ->visibles()
            ->ordenados()
            ->get();

        $tarjetas = $fondos->map(fn (Fondo $fondo): array => $this->tarjeta($fondo))->values()->all();

        return [
            'totales' => $this->totales($fondos),
            'fondos' => $tarjetas,
            'recientes' => $this->recientes($fondos->pluck('slug', 'id')->all()),
        ];
    }

    /**
     * @param  Collection<int, Fondo>  $fondos
     * @return array<string, mixed>
     */
    private function totales(Collection $fondos): array
    {
        $idsVisibles = $fondos->pluck('id')->all();
        $pendiente = $this->pendientesDe($idsVisibles);

        return [
            'recaudado' => round((float) $fondos->sum(static fn (Fondo $f): float => (float) $f->recaudado), 2),
            'donaciones' => (int) $fondos->sum('donaciones_count'),

            // Único en TODA la campaña: quien dona a dos fondos es una persona,
            // no dos. Por eso no se suman los de cada fondo.
            'donantes_unicos' => $idsVisibles === [] ? 0 : (int) Donacion::query()
                ->whereIn('fondo_id', $idsVisibles)
                ->whereIn('estado', Fondo::estadosQueSuman())
                ->distinct()
                ->count('correo'),

            /*
             * Recibido por Yape o Plin y todavía sin verificar.
             *
             * Viaja APARTE y nunca se suma a `recaudado`. Publicar como
             * recaudado un dinero que nadie ha comprobado significa que el
             * total baja en cuanto un comprobante resulta falso, y un contador
             * que baja destruye la confianza en una fundación.
             */
            'pendiente' => $pendiente['monto'],
            'pendientes_aportes' => $pendiente['aportes'],

            'moneda' => (string) ($fondos->first()?->moneda ?? config('mercadopago.currency', 'PEN')),
        ];
    }

    /**
     * Lo pendiente de los fondos visibles, sumado.
     *
     * Se filtra por los visibles y no se coge el total a secas: un fondo en
     * borrador puede tener aportes esperando, y no tiene por qué salir en una
     * cifra pública de un fondo que todavía no se ha publicado.
     *
     * @param  list<int>  $idsVisibles
     * @return array{monto: float, aportes: int}
     */
    private function pendientesDe(array $idsVisibles): array
    {
        if ($idsVisibles === []) {
            return ['monto' => 0.0, 'aportes' => 0];
        }

        $porFondo = $this->pendientes->totales()['por_fondo'];
        $monto = 0.0;
        $aportes = 0;

        foreach ($idsVisibles as $id) {
            $monto += $porFondo[$id]['monto'] ?? 0.0;
            $aportes += $porFondo[$id]['aportes'] ?? 0;
        }

        return ['monto' => round($monto, 2), 'aportes' => $aportes];
    }

    /** @return array<string, mixed> */
    private function tarjeta(Fondo $fondo): array
    {
        $m = ($this->metricas)($fondo);

        return [
            'slug' => (string) $fondo->slug,
            'nombre' => (string) $fondo->nombre,
            'resumen' => (string) $fondo->resumen,

            // El color viaja como NOMBRE de token y como clase. Nunca un hex:
            // el color lo decide el CSS (deuda técnica 3).
            'color_token' => $fondo->color_token->value,
            'clase_css' => $fondo->color_token->claseCss(),

            'imagen_portada' => $this->urlDeMedio($fondo->imagen_portada),
            'estado' => $fondo->estado->value,
            'estado_etiqueta' => $fondo->estado->etiqueta(),
            'acepta_donaciones' => $fondo->aceptaDonaciones(),

            'recaudado' => $m['recaudado'],
            'donaciones' => $m['donaciones'],
            'donantes_unicos' => $m['donantes_unicos'],

            // Sin meta no hay porcentaje: la barra se oculta, en vez de
            // enseñar un 0 % que parecería un fracaso.
            // Aparte del recaudado, siempre. Nunca sumados.
            'pendiente' => $m['pendiente'],
            'pendientes_aportes' => $m['pendientes_aportes'],

            'meta' => $m['meta'],
            'porcentaje' => $m['porcentaje'],
            'porcentaje_pendiente' => $m['porcentaje_pendiente'],
            'moneda' => $m['moneda'],
        ];
    }

    /**
     * Feed de donaciones recientes.
     *
     * Se seleccionan EXPLÍCITAMENTE las columnas que salen. Un `select *` aquí
     * sería una fuga de datos personales esperando a ocurrir: bastaría que
     * alguien añadiera una columna nueva al modelo.
     *
     * @param  array<int, string>  $slugsPorFondo
     * @return list<array<string, mixed>>
     */
    private function recientes(array $slugsPorFondo): array
    {
        if ($slugsPorFondo === []) {
            return [];
        }

        $zona = (string) config('donaciones.zona_horaria_display', 'America/Lima');

        return Donacion::query()
            ->select(['nombre', 'visible_publico', 'monto_real', 'monto_referencial', 'fondo_id', 'created_at'])
            ->whereIn('fondo_id', array_keys($slugsPorFondo))
            ->whereIn('estado', Fondo::estadosQueSuman())
            ->latest('created_at')
            ->limit((int) config('donaciones.recientes_en_feed', 10))
            ->get()
            ->map(function (Donacion $donacion) use ($slugsPorFondo, $zona): array {
                $anonimo = ! $donacion->visible_publico;

                return [
                    // Regla 2: si es anónimo, el nombre real NO sale de aquí.
                    'nombre' => $anonimo ? 'Donante anónimo' : $this->nombreAbreviado((string) $donacion->nombre),
                    'anonimo' => $anonimo,
                    'monto' => round($donacion->montoEfectivo(), 2),
                    'fondo' => $slugsPorFondo[$donacion->fondo_id] ?? '',
                    'hace' => $this->haceCuanto($donacion->created_at, $zona),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "María Pérez Quispe" → "María P."  ·  "María Elena Pérez" → "María E."
     *
     * Primera palabra completa más la inicial de la segunda: suficiente para
     * que quien donó se reconozca, insuficiente para identificar a nadie.
     *
     * NO se intenta adivinar dónde empieza el apellido. Un nombre peruano
     * puede traer uno o dos nombres de pila y uno o dos apellidos, y no hay
     * forma de saber cuál es cuál a partir de una sola cadena. Quedarse con la
     * segunda palabra es la regla que nunca se equivoca hacia el lado malo:
     * como mucho enseña una inicial de más, jamás un apellido completo.
     */
    private function nombreAbreviado(string $nombre): string
    {
        $partes = preg_split('/\s+/u', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($partes === []) {
            return 'Donante';
        }

        $pila = (string) $partes[0];

        if (count($partes) === 1) {
            return $pila;
        }

        return $pila.' '.mb_strtoupper(mb_substr((string) $partes[1], 0, 1)).'.';
    }

    /**
     * Hora relativa en español, calculada EN EL SERVIDOR con la zona de
     * visualización. El navegador no tiene por qué saber en qué zona vive la
     * fundación, ni recibir marcas de tiempo que permitan correlacionar
     * donaciones.
     */
    private function haceCuanto(?Carbon $momento, string $zona): string
    {
        if ($momento === null) {
            return '';
        }

        return $momento
            ->copy()
            ->setTimezone($zona)
            ->locale('es')
            ->diffForHumans(['parts' => 1]);
    }

    private function urlDeMedio(?string $ruta): ?string
    {
        $limpia = trim((string) $ruta);

        if ($limpia === '') {
            return null;
        }

        // Las portadas subidas desde el panel viven en uploads/fondos/<id>/…;
        // las que trae el repositorio, en media/. Se distinguen por la forma.
        return Str::startsWith($limpia, ['media/', 'uploads/', '/'])
            ? '/'.ltrim($limpia, '/')
            : '/uploads/fondos/'.$limpia;
    }

    private function claveDeCache(): string
    {
        // Los estados contables entran en la clave: si cambia
        // DASHBOARD_INCLUDE_PENDING, la respuesta cacheada deja de valer.
        return 'dashboard:publico:'.implode('-', Fondo::estadosQueSuman());
    }
}
