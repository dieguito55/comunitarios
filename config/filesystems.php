<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        | Comprobantes del canal QR. Deuda tecnica 5: en el sistema de
        | referencia vivian en una carpeta publica y cualquiera con la URL veia
        | la captura bancaria de un donante. Este disco esta fuera de public/,
        | no tiene 'url' y 'serve' esta desactivado: la unica forma de leer un
        | comprobante es una ruta autenticada protegida por Policy.
        */

        'comprobantes' => [
            'driver' => 'local',
            'root' => storage_path('app/private/comprobantes'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        /*
        | Imágenes de los fondos.
        |
        | Escribe DIRECTAMENTE dentro de public/ en lugar de usar
        | `php artisan storage:link`: en hosting compartido ese enlace
        | simbólico a veces no se puede crear, y entonces ninguna imagen del
        | panel se vería. Aquí no hay enlace que pueda faltar.
        |
        | Contrasta con el disco `comprobantes`, que es justo lo contrario: ese
        | guarda capturas bancarias y por eso vive FUERA de public/.
        */

        'fondos' => [
            'driver' => 'local',
            'root' => public_path('uploads/fondos'),
            'url' => '/uploads/fondos',
            'visibility' => 'public',
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
