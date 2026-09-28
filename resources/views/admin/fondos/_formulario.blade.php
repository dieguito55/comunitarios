{{-- Campos compartidos por el alta y la edición de un fondo. --}}

@include('admin.partials.campo', [
    'nombre' => 'nombre',
    'etiqueta' => 'Nombre del fondo',
    'valor' => $fondo->nombre,
    'requerido' => true,
    'ayuda' => 'Es el nombre que verá el donante al elegir a dónde aporta.',
    'atributos' => ['maxlength' => '200'],
])

@include('admin.partials.campo', [
    'nombre' => 'slug',
    'etiqueta' => 'Dirección web',
    'valor' => $fondo->slug,
    'requerido' => true,
    'deshabilitado' => $tieneDonaciones ?? false,
    'ayuda' => ($tieneDonaciones ?? false)
        ? 'No se puede cambiar: este fondo ya tiene donaciones, y cambiarla rompería los enlaces compartidos y los QR impresos.'
        : 'Solo minúsculas, números y guiones. Si lo dejas vacío se genera del nombre.',
    'atributos' => ['maxlength' => '160'],
])

@include('admin.partials.campo', [
    'nombre' => 'resumen',
    'etiqueta' => 'Resumen',
    'tipo' => 'textarea',
    'valor' => $fondo->resumen,
    'requerido' => true,
    'ayuda' => 'Una línea, máximo 300 caracteres. Es lo que se lee en la tarjeta del selector de fondos.',
    'atributos' => ['maxlength' => '300', 'rows' => '3'],
])

@include('admin.partials.campo', [
    'nombre' => 'descripcion',
    'etiqueta' => 'Descripción',
    'tipo' => 'textarea',
    'valor' => $fondo->descripcion,
    'ayuda' => 'Texto largo de la página del fondo. Opcional.',
    'atributos' => ['rows' => '10'],
])

@include('admin.partials.campo', [
    'nombre' => 'meta',
    'etiqueta' => 'Meta de recaudación',
    'tipo' => 'number',
    'valor' => $fondo->meta,
    'ayuda' => 'Déjala VACÍA si este fondo no tiene meta pública: entonces la barra de progreso no se muestra, en vez de enseñar un porcentaje inventado.',
    'atributos' => ['step' => '0.01', 'min' => '1', 'inputmode' => 'decimal'],
])

@include('admin.partials.campo', [
    'nombre' => 'moneda',
    'etiqueta' => 'Moneda',
    'valor' => $fondo->moneda ?? config('mercadopago.currency'),
    'requerido' => true,
    'ayuda' => 'Solo se acepta la moneda configurada en la pasarela.',
    'atributos' => ['readonly' => 'readonly', 'maxlength' => '3'],
])

<div class="admin-rejilla">
    @include('admin.partials.campo', [
        'nombre' => 'fecha_inicio',
        'etiqueta' => 'Fecha de inicio',
        'tipo' => 'date',
        'valor' => $fondo->fecha_inicio?->format('Y-m-d'),
        'ayuda' => 'Opcional.',
    ])

    @include('admin.partials.campo', [
        'nombre' => 'fecha_fin',
        'etiqueta' => 'Fecha de cierre',
        'tipo' => 'date',
        'valor' => $fondo->fecha_fin?->format('Y-m-d'),
        'ayuda' => 'Debe ser posterior a la de inicio.',
    ])
</div>

{{-- Selector de color: son NOMBRES de token de la paleta, no colores sueltos.
     El CSS traduce cada uno a su var(--token). --}}
<fieldset class="campo @error('color_token') campo--error @enderror">
    <legend>Color del fondo</legend>
    <span class="campo__ayuda" id="campo-color-ayuda">
        Se usa para destacar el fondo en el sitio. Son los colores de la marca.
    </span>

    <div class="selector-color" role="radiogroup" aria-describedby="campo-color-ayuda">
        @foreach ($colores as $color)
            <label class="{{ $color->claseCss() }}">
                <input type="radio"
                       name="color_token"
                       value="{{ $color->value }}"
                       @checked(old('color_token', $fondo->color_token?->value ?? 'teal') === $color->value)>
                <span class="fondo-muestra" aria-hidden="true"></span>
                {{ $color->etiqueta() }}
            </label>
        @endforeach
    </div>

    @error('color_token')
        <strong class="campo__error">{{ $message }}</strong>
    @enderror
</fieldset>

@include('admin.partials.campo', [
    'nombre' => 'orden',
    'etiqueta' => 'Orden en el listado',
    'tipo' => 'number',
    'valor' => $fondo->orden ?? 0,
    'requerido' => true,
    'ayuda' => 'Número más bajo, aparece antes.',
    'atributos' => ['min' => '0', 'max' => '9999', 'step' => '1'],
])

@include('admin.partials.campo', [
    'nombre' => 'video',
    'etiqueta' => 'Vídeo',
    'valor' => $fondo->video,
    'ayuda' => 'Ruta relativa dentro de public/, por ejemplo media/fondo_antonia.mp4',
    'atributos' => ['maxlength' => '255'],
])

@include('admin.partials.campo', [
    'nombre' => 'imagen_portada',
    'etiqueta' => 'Imagen de portada',
    'tipo' => 'file',
    'ayuda' => 'JPG, PNG o WEBP. Mínimo 1200×630 píxeles (es la imagen que se ve al compartir el fondo) y máximo 4 MB. Si pesa más, comprímela antes de subirla.',
    'atributos' => ['accept' => 'image/jpeg,image/png,image/webp'],
])
