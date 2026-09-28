@props([
    'nombre',
    'etiqueta',
    'tipo' => 'text',
    'valor' => null,
    'ayuda' => null,
    'requerido' => false,
    'deshabilitado' => false,
    'atributos' => [],
])

@php
    $id = 'campo-' . $nombre;
    $idError = $id . '-error';
    $idAyuda = $id . '-ayuda';
    $hayError = $errors->has($nombre);

    // aria-describedby enlaza el campo con su ayuda y con su error, para que un
    // lector de pantalla los lea al enfocarlo y no haya que buscarlos.
    $descritoPor = array_filter([
        $ayuda ? $idAyuda : null,
        $hayError ? $idError : null,
    ]);
@endphp

<div class="campo @if($hayError) campo--error @endif">
    <label for="{{ $id }}">
        {{ $etiqueta }}
        @if($requerido) <span aria-hidden="true">*</span><span class="visually-hidden"> (obligatorio)</span> @endif
    </label>

    @if($ayuda)
        <span class="campo__ayuda" id="{{ $idAyuda }}">{{ $ayuda }}</span>
    @endif

    @if($tipo === 'textarea')
        <textarea id="{{ $id }}"
                  name="{{ $nombre }}"
                  @if($requerido) required @endif
                  @if($deshabilitado) disabled @endif
                  @if($descritoPor) aria-describedby="{{ implode(' ', $descritoPor) }}" @endif
                  @if($hayError) aria-invalid="true" @endif
                  @foreach($atributos as $clave => $v) {{ $clave }}="{{ $v }}" @endforeach
        >{{ old($nombre, $valor) }}</textarea>
    @else
        <input type="{{ $tipo }}"
               id="{{ $id }}"
               name="{{ $nombre }}"
               @if($tipo !== 'file') value="{{ old($nombre, $valor) }}" @endif
               @if($requerido) required @endif
               @if($deshabilitado) disabled @endif
               @if($descritoPor) aria-describedby="{{ implode(' ', $descritoPor) }}" @endif
               @if($hayError) aria-invalid="true" @endif
               @foreach($atributos as $clave => $v) {{ $clave }}="{{ $v }}" @endforeach
        >
    @endif

    @error($nombre)
        <strong class="campo__error" id="{{ $idError }}">{{ $message }}</strong>
    @enderror
</div>
