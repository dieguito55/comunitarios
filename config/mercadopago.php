<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mercado Pago — Checkout Pro
|--------------------------------------------------------------------------
|
| Único punto donde se leen las credenciales de la pasarela. Ningún servicio,
| controlador ni job llama a env(): fuera de config/ env() devuelve null en
| cuanto se ejecuta `php artisan config:cache`.
|
| Las reglas duras que se citan aquí vienen de depurar el sistema de referencia
| con dinero real. Romperlas cuesta pagos.
|
*/

return [

    /*
    | sandbox | production. Regla dura 8: en sandbox NUNCA se envía a MP el
    | correo real del donante como payer.email — MP rechaza el pago si el
    | pagador no es un usuario de prueba.
    */
    'env' => env('MP_ENV', 'sandbox'),

    /*
    | Credenciales. TEST-... en sandbox, APP_USR-... en producción.
    | Regla dura 7: con APP_USR- se usa init_point; con TEST- clásicas,
    | sandbox_init_point. Mezclarlos produce "Algo salió mal" en el checkout.
    */
    'access_token' => env('MP_ACCESS_TOKEN', ''),
    'public_key' => env('MP_PUBLIC_KEY', ''),

    /*
    | Clave secreta del webhook (Panel de MP → Webhooks). Deuda técnica 9: sin
    | esto no se puede validar la cabecera x-signature y cualquiera podría
    | marcar donaciones como aprobadas.
    */
    'webhook_secret' => env('MP_WEBHOOK_SECRET', ''),

    /* URL pública que recibe las notificaciones. En local, un túnel ngrok. */
    'webhook_url' => env('MP_WEBHOOK_URL', ''),

    /* Ver regla dura 7. */
    'use_sandbox_init_point' => (bool) env('MP_USE_SANDBOX_INIT_POINT', false),

    'currency' => env('MP_CURRENCY', 'PEN'),

    /*
    | Regla dura 3: auto_return = 'approved' solo si back_urls.success es HTTPS.
    | Con HTTP, MP rechaza la creación de la preferencia con error 400. Quien
    | construya la preferencia (fase 2) debe comprobarlo en tiempo de ejecución.
    */
    'back_urls' => [
        'success' => env('MP_BACK_URL_SUCCESS', ''),
        'failure' => env('MP_BACK_URL_FAILURE', ''),
        'pending' => env('MP_BACK_URL_PENDING', ''),
    ],

    /* Correo de un usuario de prueba de MP. Ver regla dura 8. */
    'sandbox_payer_email' => env('MP_SANDBOX_PAYER_EMAIL', ''),

    /* Texto en el estado de cuenta de la tarjeta. Máx. 22 caracteres en MP. */
    'statement_descriptor' => env('MP_STATEMENT_DESCRIPTOR', 'COMUNITARIOS'),

    /* Segundos de espera de las llamadas HTTP a la API de MP. */
    'timeout' => (int) env('MP_TIMEOUT', 15),

];
