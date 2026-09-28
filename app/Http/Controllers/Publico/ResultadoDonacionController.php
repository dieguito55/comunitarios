<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GET /donacion/resultado — la vuelta del checkout de Mercado Pago.
 *
 * Aquí arranca la segunda red de seguridad: el navegador del donante llama a
 * /api/donaciones/reconciliar con lo que traiga y el servidor confirma el
 * estado real contra Mercado Pago.
 *
 * ⚠️ El `?donacion=` de la URL solo sirve para pintar un mensaje INICIAL
 * razonable si el JavaScript no llega a ejecutarse. NO decide nada: cualquiera
 * puede escribir `?donacion=exitosa&status=approved` a mano. El estado real lo
 * establece el servidor consultando la API de MP, y el JS sustituye este
 * mensaje en cuanto responde.
 */
final class ResultadoDonacionController extends Controller
{
    /** Lo que MP pone en la URL, mapeado al mensaje provisional. */
    private const MENSAJES = [
        'exitosa' => 'aprobado',
        'pendiente' => 'en_proceso',
        'fallida' => 'rechazado',
    ];

    public function __invoke(Request $peticion): View
    {
        $declarado = mb_strtolower(trim((string) $peticion->query('donacion', '')));

        return view('publico.resultado', [
            // "provisional" en el nombre, para que nadie lo confunda con un
            // estado confirmado al leer la plantilla.
            'estadoProvisional' => self::MENSAJES[$declarado] ?? 'desconocido',
            'correoContacto' => (string) config('mail.from.address', ''),
        ]);
    }
}
