@include('admin.partials.campo', [
    'nombre' => 'username',
    'etiqueta' => 'Nombre de usuario',
    'valor' => $usuario->username,
    'requerido' => true,
    'ayuda' => 'Letras, números, punto, guion y guion bajo.',
    'atributos' => ['maxlength' => '50', 'autocomplete' => 'username'],
])

@include('admin.partials.campo', [
    'nombre' => 'password',
    'etiqueta' => 'Contraseña',
    'tipo' => 'password',
    'requerido' => $usuario->exists === false,
    'ayuda' => $usuario->exists
        ? 'Déjala vacía para no cambiarla. Si la cambias, mínimo 12 caracteres.'
        : 'Mínimo 12 caracteres.',
    'atributos' => ['autocomplete' => 'new-password'],
])

@include('admin.partials.campo', [
    'nombre' => 'password_confirmation',
    'etiqueta' => 'Repite la contraseña',
    'tipo' => 'password',
    'requerido' => $usuario->exists === false,
    'atributos' => ['autocomplete' => 'new-password'],
])

<div class="campo @error('role') campo--error @enderror">
    <label for="campo-role">Rol</label>
    <span class="campo__ayuda" id="campo-role-ayuda">
        El editor solo puede cambiar textos e imágenes de fondos que ya existen.
        @if ($esYo ?? false)
            No puedes cambiar tu propio rol.
        @endif
    </span>

    <select id="campo-role"
            name="role"
            aria-describedby="campo-role-ayuda @error('role') campo-role-error @enderror"
            @if ($esYo ?? false) disabled @endif
            @error('role') aria-invalid="true" @enderror>
        @foreach ($roles as $rol)
            <option value="{{ $rol->value }}" @selected(old('role', $usuario->role?->value) === $rol->value)>
                {{ $rol->etiqueta() }}
            </option>
        @endforeach
    </select>

    @error('role')
        <strong class="campo__error" id="campo-role-error">{{ $message }}</strong>
    @enderror
</div>

@if ($esYo ?? false)
    {{-- Deshabilitado no se envía: se manda el rol actual para que la
         validación siga teniendo un valor, y el controlador impide el cambio. --}}
    <input type="hidden" name="role" value="{{ $usuario->role->value }}">
@endif
