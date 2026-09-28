<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reglas de negocio del módulo de donaciones
|--------------------------------------------------------------------------
|
| Montos, meta de campaña y política del dashboard público. Los mínimos se
| validan SIEMPRE en el servidor: lo que llega del navegador no se cree.
|
*/

return [

    /* Mínimo aceptado en el checkout de Mercado Pago, en la moneda de la campaña. */
    'monto_minimo_mp' => (float) env('DONACION_MIN_MP', 5),

    /* Mínimo aceptado en el canal QR (Yape/Plin con comprobante). */
    'monto_minimo_qr' => (float) env('DONACION_MIN_QR', 10),

    /* Techo por donación. Contiene errores de tecleo y abusos del formulario. */
    'monto_maximo' => (float) env('DONACION_MAX', 10000),

    /*
    | Deuda técnica 6 y 7: en el sistema de referencia esta bandera estaba
    | declarada pero no se usaba, y los pendientes sumaban al total público.
    | Aquí manda de verdad, y por defecto es false: solo el dinero confirmado
    | se rinde en cuentas. Un total que baja porque un pago se rechazó genera
    | desconfianza.
    */
    'dashboard_include_pending' => (bool) env('DASHBOARD_INCLUDE_PENDING', false),

    /*
    | Meta de recaudación de la campaña, en la moneda de la campaña.
    | null = todavía no definida: la barra de progreso debe ocultarse, no
    | mostrar 0 % ni un porcentaje inventado.
    */
    'campana_meta' => is_numeric(env('CAMPANA_META')) ? (float) env('CAMPANA_META') : null,

    /*
    | Ruta pública de la imagen del QR estático de la organización.
    |
    | El valor por defecto apunta al archivo que viaja en el propio repositorio
    | (`public/qr/qr.jpeg`), no a una cadena vacía: así el canal funciona en
    | cualquier instalación recién clonada. La variable de entorno existe para
    | poder cambiarlo sin desplegar, por ejemplo si la organización renueva su
    | QR o lo sirve desde otro sitio.
    */
    'qr_imagen' => env('QR_YAPE_IMAGEN', '/qr/qr.jpeg'),

    /*
    | Comprobantes del canal QR. Deuda técnica 5: viven en un disco PRIVADO y
    | solo se sirven por una ruta autenticada con Policy, nunca por URL directa.
    */
    'comprobante' => [
        'disco' => 'comprobantes',
        'max_mb' => 10,

        /*
        | MIME REAL del archivo, detectado leyendo su contenido — nunca la
        | extensión ni lo que declare el navegador.
        */
        'mimes_permitidos' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
        ],
    ],

    /*
    | Horas tras las cuales una donación de Mercado Pago que sigue sin
    | confirmarse se considera abandonada y deja de reintentarse.
    */
    'expiracion_horas' => (int) env('DONACION_EXPIRACION_HORAS', 24),

    /*
    | Vigencia del ENLACE de pago de Mercado Pago. Es otra cosa que
    | `expiracion_horas`: aquella es nuestra barrida interna de pendientes; esta
    | viaja a MP dentro de la preferencia para que nadie pague un enlace viejo.
    | Dos conceptos, dos claves.
    */
    'preferencia_expira_horas' => (int) env('MP_PREFERENCIA_EXPIRA_HORAS', 24),

    /*
    | Montos sugeridos en el formulario, en la moneda de la campaña.
    |
    | Viven aquí y no incrustados en el Blade porque son una decisión de
    | tesorería: cambiarlos no debería obligar a tocar una plantilla.
    */
    'montos_sugeridos' => [20, 50, 100, 200],

    /*
    | Zona horaria SOLO para mostrar y exportar.
    |
    | Todo se guarda en UTC (decisión de la fase 2B). Esta clave es la que
    | convierte al leer: "hace 3 minutos" y las fechas del panel se calculan
    | con la hora de Lima, que es la que entiende quien lee.
    */
    'zona_horaria_display' => env('APP_DISPLAY_TIMEZONE', 'America/Lima'),

    /*
    | Vida de la caché del dashboard público. Es una consulta agregada que se
    | pide cada vez que alguien abre la portada, y además se refresca sola cada
    | 30 s desde el navegador: sin caché, cada visita sería un SUM sobre toda la
    | tabla de donaciones.
    */
    'cache_dashboard_segundos' => 30,

    /* Cuántas donaciones recientes se muestran en el feed público. */
    'recientes_en_feed' => 10,

    /*
    | Deuda técnica 10: el sistema de referencia no tenía ningún límite en los
    | endpoints públicos. Estos topes son por IP y solo acotan el abuso del
    | formulario; no son una regla de negocio sobre cuánto puede donar alguien.
    */
    'limite_peticiones' => [
        'por_minuto' => 8,
        'por_dia' => 40,

    ],

];
