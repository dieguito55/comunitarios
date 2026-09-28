<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoFondo;
use App\Enums\TipoAportante;
use App\Models\Fondo;
use App\Rules\FondoAceptaDonaciones;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Validación de servidor del formulario de donación.
 *
 * TODA validación relevante ocurre aquí. La del navegador existe solo para que
 * el donante no tenga que esperar al servidor para ver un error de tecleo.
 */
/*
 * No es `final` a proposito: CrearDonacionQrRequest la extiende para reutilizar
 * la validacion del donante —nombre, documento, correo, fondo, terminos— y solo
 * cambiar lo que de verdad difiere entre canales: el minimo del monto y los
 * campos del comprobante. Duplicar estas 150 lineas seria garantizar que dentro
 * de unos meses los dos canales validen distinto al mismo donante.
 */
class CrearDonacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza antes de validar, para que las reglas y lo que se guarda en la
     * base de datos sean exactamente el mismo valor.
     */
    protected function prepareForValidation(): void
    {
        $nombre = preg_replace('/\s+/u', ' ', trim((string) $this->input('nombre', '')));
        $documento = preg_replace('/\s+/u', '', (string) $this->input('documento', ''));

        $this->merge([
            'nombre' => strip_tags(is_string($nombre) ? $nombre : ''),
            'documento' => mb_strtoupper(is_string($documento) ? $documento : '', 'UTF-8'),
            'correo' => mb_strtolower(trim((string) $this->input('correo', '')), 'UTF-8'),
            'telefono' => trim((string) $this->input('telefono', '')),
            'moneda' => mb_strtoupper(trim((string) $this->input('moneda', (string) config('mercadopago.currency'))), 'UTF-8'),
            'tipo_aportante' => trim((string) $this->input('tipo_aportante', TipoAportante::PERSONA->value)),

            // Si el donante no eligió fondo y solo hay uno abierto, es ese. Con
            // dos o más abiertos NO se adivina: elegir por él podría mandar el
            // dinero a un proyecto que no era.
            'fondo_id' => $this->fondoElegido(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'min:3', 'max:200'],

            // DNI (8) / RUC (11) / pasaporte. Ya viene sin espacios y en mayúsculas.
            'documento' => ['required', 'string', 'regex:/^[A-Z0-9\-]{5,20}$/'],

            'correo' => ['required', 'string', 'email:rfc', 'max:200'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'tipo_aportante' => ['required', Rule::enum(TipoAportante::class)],

            'fondo_id' => ['required', 'integer', 'exists:fondos,id', new FondoAceptaDonaciones],

            'monto' => [
                'required',
                'numeric',
                'min:'.(float) config('donaciones.monto_minimo_mp'),
                'max:'.(float) config('donaciones.monto_maximo'),
            ],

            // Una sola moneda configurada: aceptar otras exigiría decidir tipo
            // de cambio y rendición por moneda, que no está definido.
            'moneda' => ['required', 'string', Rule::in([(string) config('mercadopago.currency')])],

            'visible_publico' => ['nullable', 'boolean'],
            'acepta_terminos' => ['accepted'],
        ];
    }

    /**
     * El fondo que indicó el donante o, si no indicó ninguno y solo hay uno
     * abierto, ese. Devuelve null cuando hay varios abiertos: entonces la regla
     * `required` obliga a elegir.
     */
    private function fondoElegido(): ?int
    {
        $elegido = trim((string) $this->input('fondo_id', ''));

        if ($elegido !== '' && ctype_digit($elegido)) {
            return (int) $elegido;
        }

        $abiertos = Fondo::query()
            ->where('estado', EstadoFondo::ACTIVO)
            ->orderBy('orden')
            ->orderBy('id')
            ->limit(2)
            ->pluck('id');

        return $abiertos->count() === 1 ? (int) $abiertos->first() : null;
    }

    /**
     * Mensajes en español para TODA regla que pueda dispararse.
     *
     * No es cosmético: el proyecto corre con APP_LOCALE=es y no tiene
     * `lang/es/validation.php`, así que cualquier regla sin mensaje propio
     * saldría en inglés (o, si el fallback también fuera `es`, como la clave
     * cruda `validation.required`). El donante nunca debe ver eso.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Necesitamos tu nombre para registrar el aporte.',
            'nombre.string' => 'Escribe tu nombre con letras.',
            'nombre.min' => 'El nombre debe tener entre 3 y 200 caracteres.',
            'nombre.max' => 'El nombre debe tener entre 3 y 200 caracteres.',

            'documento.required' => 'Necesitamos tu documento de identidad.',
            'documento.string' => 'Escribe tu documento con letras y números, sin espacios.',
            'documento.regex' => 'El documento solo puede llevar letras, números y guiones, entre 5 y 20 caracteres. Un DNI son 8 dígitos; un RUC, 11.',

            'correo.required' => 'Necesitamos un correo para enviarte la constancia.',
            'correo.string' => 'El correo no parece válido. Revisa que tenga @ y un dominio, como nombre@correo.com.',
            'correo.email' => 'El correo no parece válido. Revisa que tenga @ y un dominio, como nombre@correo.com.',
            'correo.max' => 'El correo no puede superar los 200 caracteres.',

            'telefono.string' => 'Escribe el teléfono solo con números, espacios y el signo +.',
            'telefono.max' => 'El teléfono no puede superar los 30 caracteres.',

            'tipo_aportante.required' => 'Indica si donas como persona o como empresa.',
            'tipo_aportante.enum' => 'Indica si donas como persona o como empresa.',
            'tipo_aportante.Illuminate\Validation\Rules\Enum' => 'Indica si donas como persona o como empresa.',

            'fondo_id.required' => 'Elige a qué fondo quieres aportar.',
            'fondo_id.integer' => 'Elige a qué fondo quieres aportar.',
            'fondo_id.exists' => 'Ese fondo no está recibiendo donaciones en este momento.',

            'monto.required' => 'Debes ingresar un monto para donar.',
            'monto.numeric' => 'Escribe el monto solo con números, por ejemplo 50 o 50.00.',
            'monto.min' => 'El monto mínimo es :min y el máximo '.number_format((float) config('donaciones.monto_maximo'), 0).'.',
            'monto.max' => 'El monto máximo por donación es '.number_format((float) config('donaciones.monto_maximo'), 0).'. Si quieres aportar más, escríbenos.',

            // Estas tres no las puede provocar el donante: la moneda la fija
            // la configuración y el formulario la envía sola. Si aparecen, es
            // que algo va mal de nuestro lado, y el mensaje lo dice sin
            // culpar a quien está intentando donar.
            'moneda.required' => 'Hubo un problema con la configuración de la donación. Recarga la página e inténtalo otra vez.',
            'moneda.string' => 'Hubo un problema con la configuración de la donación. Recarga la página e inténtalo otra vez.',
            'moneda.in' => 'Hubo un problema con la configuración de la donación. Recarga la página e inténtalo otra vez.',

            'visible_publico.boolean' => 'No pudimos leer tu preferencia de anonimato. Marca o desmarca la casilla otra vez.',

            'acepta_terminos.accepted' => 'Necesitas aceptar los términos y la política de privacidad para continuar.',
        ];
    }

    /**
     * Nombres en español por si alguna regla futura no tuviera mensaje propio.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            'documento' => 'documento',
            'correo' => 'correo',
            'telefono' => 'teléfono',
            'tipo_aportante' => 'tipo de aportante',
            'fondo_id' => 'fondo',
            'monto' => 'monto',
            'moneda' => 'moneda',
            'visible_publico' => 'visibilidad pública',
            'acepta_terminos' => 'aceptación de términos',
        ];
    }

    /**
     * Datos limpios y tipados, listos para el servicio.
     *
     * `monto` alimenta monto_referencial: es lo que el donante DECLARÓ, no lo
     * que entrará. monto_real lo fija después el webhook o la verificación.
     *
     * @return array<string, mixed>
     */
    public function datosDonacion(): array
    {
        /** @var array<string, mixed> $validado */
        $validado = $this->validated();

        $telefono = trim((string) ($validado['telefono'] ?? ''));

        return [
            'nombre' => (string) $validado['nombre'],
            'documento' => (string) $validado['documento'],
            'correo' => (string) $validado['correo'],
            'telefono' => $telefono !== '' ? $telefono : null,
            'tipo_aportante' => TipoAportante::from((string) $validado['tipo_aportante']),
            'fondo_id' => (int) $validado['fondo_id'],
            'monto' => round((float) $validado['monto'], 2),
            'moneda' => (string) $validado['moneda'],

            // Ausente = visible. El anonimato es una decisión explícita del donante.
            'visible_publico' => $this->boolean('visible_publico', true),
            'acepta_terminos' => true,
        ];
    }

    /**
     * Un solo contrato de error para todo el endpoint: `error` es el mensaje
     * que el front muestra tal cual; `errores` es la lista completa.
     */
    protected function failedValidation(Validator $validator): void
    {
        /** @var list<string> $mensajes */
        $mensajes = array_values($validator->errors()->all());

        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => $mensajes[0] ?? 'Datos inválidos.',
            'errores' => $mensajes,

            // Por campo, para que el formulario pueda marcar EL campo que falla
            // en vez de solo enseñar un aviso arriba. El navegador ya sabía
            // leer esta clave; hasta ahora no se enviaba nunca.
            'campos' => array_map(
                static fn (array $mensajesDelCampo): string => (string) ($mensajesDelCampo[0] ?? ''),
                $validator->errors()->toArray(),
            ),
        ], 422));
    }
}
