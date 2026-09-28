<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mensajes de validación en español
|--------------------------------------------------------------------------
|
| Sin este archivo, cualquier regla sin mensaje propio se renderiza como la
| clave cruda ("validation.required") en cuanto APP_FALLBACK_LOCALE vale "es".
| Ese fue el bug que apareció en la fase 2A y que obligó a dejar el fallback en
| inglés; con esta traducción el proyecto ya puede tener las dos en español.
|
| `attributes`, al final, es lo que hace que el mensaje diga "El nombre del
| fondo es obligatorio" en vez de "El campo nombre es obligatorio".
|
*/

return [

    'accepted' => 'Debes aceptar :attribute.',
    'accepted_if' => 'Debes aceptar :attribute cuando :other sea :value.',
    'active_url' => ':Attribute no es una URL válida.',
    'after' => ':Attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => ':Attribute debe ser una fecha posterior o igual a :date.',
    'alpha' => ':Attribute solo puede contener letras.',
    'alpha_dash' => ':Attribute solo puede contener letras, números, guiones y guiones bajos.',
    'alpha_num' => ':Attribute solo puede contener letras y números.',
    'array' => ':Attribute debe ser una lista.',
    'ascii' => ':Attribute solo puede contener caracteres alfanuméricos y símbolos de un byte.',
    'before' => ':Attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => ':Attribute debe ser una fecha anterior o igual a :date.',
    'between' => [
        'array' => ':Attribute debe tener entre :min y :max elementos.',
        'file' => ':Attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => ':Attribute debe estar entre :min y :max.',
        'string' => ':Attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => ':Attribute debe ser verdadero o falso.',
    'can' => ':Attribute contiene un valor no permitido.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'contains' => 'A :attribute le falta un valor obligatorio.',
    'current_password' => 'La contraseña es incorrecta.',
    'date' => ':Attribute no es una fecha válida.',
    'date_equals' => ':Attribute debe ser una fecha igual a :date.',
    'date_format' => ':Attribute no corresponde al formato :format.',
    'decimal' => ':Attribute debe tener :decimal decimales.',
    'declined' => 'Debes rechazar :attribute.',
    'declined_if' => 'Debes rechazar :attribute cuando :other sea :value.',
    'different' => ':Attribute y :other deben ser distintos.',
    'digits' => ':Attribute debe tener :digits dígitos.',
    'digits_between' => ':Attribute debe tener entre :min y :max dígitos.',
    'dimensions' => ':Attribute tiene dimensiones de imagen no válidas.',
    'distinct' => ':Attribute tiene un valor duplicado.',
    'doesnt_end_with' => ':Attribute no puede terminar con ninguno de estos valores: :values.',
    'doesnt_start_with' => ':Attribute no puede empezar con ninguno de estos valores: :values.',
    'email' => ':Attribute no es un correo electrónico válido.',
    'ends_with' => ':Attribute debe terminar con alguno de estos valores: :values.',
    'enum' => 'El valor de :attribute no es válido.',
    'exists' => 'El valor de :attribute no existe.',
    'extensions' => ':Attribute debe tener una de estas extensiones: :values.',
    'file' => ':Attribute debe ser un archivo.',
    'filled' => ':Attribute no puede quedarse vacío.',
    'gt' => [
        'array' => ':Attribute debe tener más de :value elementos.',
        'file' => ':Attribute debe pesar más de :value kilobytes.',
        'numeric' => ':Attribute debe ser mayor que :value.',
        'string' => ':Attribute debe tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => ':Attribute debe tener :value elementos o más.',
        'file' => ':Attribute debe pesar :value kilobytes o más.',
        'numeric' => ':Attribute debe ser mayor o igual que :value.',
        'string' => ':Attribute debe tener :value caracteres o más.',
    ],
    'hex_color' => ':Attribute debe ser un color hexadecimal válido.',
    'image' => ':Attribute debe ser una imagen.',
    'in' => 'El valor de :attribute no es válido.',
    'in_array' => 'El valor de :attribute no existe en :other.',
    'in_array_keys' => ':Attribute debe contener al menos una de estas claves: :values.',
    'integer' => ':Attribute debe ser un número entero.',
    'ip' => ':Attribute debe ser una dirección IP válida.',
    'ipv4' => ':Attribute debe ser una dirección IPv4 válida.',
    'ipv6' => ':Attribute debe ser una dirección IPv6 válida.',
    'json' => ':Attribute debe ser una cadena JSON válida.',
    'list' => ':Attribute debe ser una lista.',
    'lt' => [
        'array' => ':Attribute debe tener menos de :value elementos.',
        'file' => ':Attribute debe pesar menos de :value kilobytes.',
        'numeric' => ':Attribute debe ser menor que :value.',
        'string' => ':Attribute debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => ':Attribute no debe tener más de :value elementos.',
        'file' => ':Attribute debe pesar :value kilobytes o menos.',
        'numeric' => ':Attribute debe ser menor o igual que :value.',
        'string' => ':Attribute debe tener :value caracteres o menos.',
    ],
    'mac_address' => ':Attribute debe ser una dirección MAC válida.',
    'max' => [
        'array' => ':Attribute no debe tener más de :max elementos.',
        'file' => ':Attribute no debe pesar más de :max kilobytes.',
        'numeric' => ':Attribute no debe ser mayor que :max.',
        'string' => ':Attribute no debe tener más de :max caracteres.',
    ],
    'max_digits' => ':Attribute no debe tener más de :max dígitos.',
    'mimes' => ':Attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => ':Attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'array' => ':Attribute debe tener al menos :min elementos.',
        'file' => ':Attribute debe pesar al menos :min kilobytes.',
        'numeric' => ':Attribute debe ser al menos :min.',
        'string' => ':Attribute debe tener al menos :min caracteres.',
    ],
    'min_digits' => ':Attribute debe tener al menos :min dígitos.',
    'missing' => ':Attribute no puede estar presente.',
    'missing_if' => ':Attribute no puede estar presente cuando :other sea :value.',
    'missing_unless' => ':Attribute no puede estar presente salvo que :other sea :value.',
    'missing_with' => ':Attribute no puede estar presente si hay :values.',
    'missing_with_all' => ':Attribute no puede estar presente si hay :values.',
    'multiple_of' => ':Attribute debe ser múltiplo de :value.',
    'not_in' => 'El valor de :attribute no es válido.',
    'not_regex' => 'El formato de :attribute no es válido.',
    'numeric' => ':Attribute debe ser un número.',
    'password' => [
        'letters' => ':Attribute debe contener al menos una letra.',
        'mixed' => ':Attribute debe contener al menos una mayúscula y una minúscula.',
        'numbers' => ':Attribute debe contener al menos un número.',
        'symbols' => ':Attribute debe contener al menos un símbolo.',
        'uncompromised' => 'Esta contraseña ha aparecido en una filtración de datos. Elige otra.',
    ],
    'present' => ':Attribute debe estar presente.',
    'present_if' => ':Attribute debe estar presente cuando :other sea :value.',
    'present_unless' => ':Attribute debe estar presente salvo que :other sea :value.',
    'present_with' => ':Attribute debe estar presente si hay :values.',
    'present_with_all' => ':Attribute debe estar presente si hay :values.',
    'prohibited' => ':Attribute no está permitido.',
    'prohibited_if' => ':Attribute no está permitido cuando :other sea :value.',
    'prohibited_if_accepted' => ':Attribute no está permitido cuando se acepta :other.',
    'prohibited_if_declined' => ':Attribute no está permitido cuando se rechaza :other.',
    'prohibited_unless' => ':Attribute no está permitido salvo que :other esté en :values.',
    'prohibits' => ':Attribute impide que :other esté presente.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => ':Attribute es obligatorio.',
    'required_array_keys' => ':Attribute debe contener entradas para: :values.',
    'required_if' => ':Attribute es obligatorio cuando :other es :value.',
    'required_if_accepted' => ':Attribute es obligatorio cuando se acepta :other.',
    'required_if_declined' => ':Attribute es obligatorio cuando se rechaza :other.',
    'required_unless' => ':Attribute es obligatorio salvo que :other esté en :values.',
    'required_with' => ':Attribute es obligatorio cuando hay :values.',
    'required_with_all' => ':Attribute es obligatorio cuando hay :values.',
    'required_without' => ':Attribute es obligatorio cuando no hay :values.',
    'required_without_all' => ':Attribute es obligatorio cuando no hay ninguno de :values.',
    'same' => ':Attribute y :other deben coincidir.',
    'size' => [
        'array' => ':Attribute debe contener :size elementos.',
        'file' => ':Attribute debe pesar :size kilobytes.',
        'numeric' => ':Attribute debe ser :size.',
        'string' => ':Attribute debe tener :size caracteres.',
    ],
    'starts_with' => ':Attribute debe empezar con alguno de estos valores: :values.',
    'string' => ':Attribute debe ser texto.',
    'timezone' => ':Attribute debe ser una zona horaria válida.',
    'unique' => ':Attribute ya está en uso.',
    'uploaded' => 'No se pudo subir :attribute.',
    'uppercase' => ':Attribute debe estar en mayúsculas.',
    'url' => ':Attribute debe ser una URL válida.',
    'ulid' => ':Attribute debe ser un ULID válido.',
    'uuid' => ':Attribute debe ser un UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Mensajes propios de un campo concreto
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'imagen_portada' => [
            'dimensions' => 'La imagen de portada debe medir al menos 1200×630 píxeles: es la que se ve al compartir el fondo en redes.',
            'max' => 'La imagen de portada no debe pesar más de 4 MB. Comprímela antes de subirla.',
            'mimes' => 'La imagen de portada debe ser JPG, PNG o WEBP.',
        ],
        'password' => [
            'min' => 'La contraseña debe tener al menos :min caracteres.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nombres de los campos
    |--------------------------------------------------------------------------
    |
    | Para que el mensaje diga "El nombre del fondo es obligatorio" en vez de
    | "El campo nombre es obligatorio".
    |
    */

    'attributes' => [
        // Fondos
        'nombre' => 'el nombre del fondo',
        'slug' => 'la dirección web del fondo',
        'resumen' => 'el resumen',
        'descripcion' => 'la descripción',
        'meta' => 'la meta de recaudación',
        'moneda' => 'la moneda',
        'fecha_inicio' => 'la fecha de inicio',
        'fecha_fin' => 'la fecha de cierre',
        'color_token' => 'el color',
        'orden' => 'el orden',
        'estado' => 'el estado',
        'imagen_portada' => 'la imagen de portada',
        'video' => 'el vídeo',
        'medio' => 'el archivo',
        'alt' => 'el texto alternativo',
        'tipo' => 'el tipo',

        // Donaciones
        'fondo_id' => 'el fondo',
        'documento' => 'el documento',
        'correo' => 'el correo',
        'telefono' => 'el teléfono',
        'tipo_aportante' => 'el tipo de aportante',
        'monto' => 'el monto',
        'visible_publico' => 'la visibilidad pública',
        'acepta_terminos' => 'los términos y la política de privacidad',
        'donacion_id' => 'el identificador de la donación',
        'payment_id' => 'el identificador del pago',
        'preference_id' => 'el identificador de la preferencia',

        // Administradores
        'username' => 'el nombre de usuario',
        'password' => 'la contraseña',
        'password_confirmation' => 'la confirmación de la contraseña',
        'role' => 'el rol',
        'confirmacion' => 'la confirmación',
    ],

];
