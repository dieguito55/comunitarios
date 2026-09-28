<?php

declare(strict_types=1);

namespace App\Services\Fondos;

use App\Models\Fondo;
use App\Rules\ImagenSegura;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Guarda una imagen de un fondo en el disco público `fondos`.
 *
 * Tres decisiones que evitan tres problemas distintos:
 *
 *  1. **El nombre lo ponemos nosotros**, siempre: un hash aleatorio más la
 *     extensión que corresponde al tipo REAL del archivo. El nombre que manda
 *     el navegador puede traer `../`, caracteres que el sistema de archivos
 *     interpreta, o una segunda extensión (`foto.jpg.php`). Nunca se usa.
 *
 *  2. **La extensión sale del contenido**, no de lo que traía el archivo. Si
 *     alguien sube un PNG llamado `.jpg`, se guarda como `.png`.
 *
 *  3. **Cada fondo tiene su carpeta** (`uploads/fondos/<id>/`), para poder
 *     borrarla entera cuando se elimina un fondo en borrador.
 *
 * El disco escribe directamente dentro de `public/` en lugar de usar
 * `storage:link`: en hosting compartido ese enlace simbólico a veces no se
 * puede crear, y entonces ninguna imagen se ve.
 */
final class GuardarImagenFondo
{
    private const DISCO = 'fondos';

    /**
     * @param  string|null  $anterior  Ruta relativa de la imagen a reemplazar.
     * @return string Ruta relativa dentro del disco, lista para guardar en la BD.
     */
    public function __invoke(Fondo $fondo, UploadedFile $archivo, ?string $anterior = null): string
    {
        $ruta = $archivo->getRealPath();

        if ($ruta === false) {
            throw new RuntimeException('No se pudo leer el archivo subido.');
        }

        $extension = ImagenSegura::extensionDe(ImagenSegura::tipoReal($ruta));

        if ($extension === null) {
            // Si se llega aquí, la validación no hizo su trabajo.
            throw new RuntimeException('El archivo no es una imagen admitida.');
        }

        $destino = $this->carpetaDe($fondo).'/'.Str::random(40).'.'.$extension;

        $guardado = Storage::disk(self::DISCO)->putFileAs(
            $this->carpetaDe($fondo),
            $archivo,
            basename($destino)
        );

        if ($guardado === false) {
            throw new RuntimeException('No se pudo guardar la imagen. Revisa los permisos de public/uploads/fondos.');
        }

        // La anterior se borra DESPUÉS de que la nueva esté en disco: si algo
        // falla antes, el fondo se queda con la imagen que ya tenía.
        if ($anterior !== null && $anterior !== '' && $anterior !== $guardado) {
            $this->eliminar($anterior);
        }

        return $guardado;
    }

    /** Borra un archivo concreto. No lanza: perder la imagen vieja no es grave. */
    public function eliminar(?string $rutaRelativa): void
    {
        $ruta = trim((string) $rutaRelativa);

        if ($ruta === '') {
            return;
        }

        try {
            Storage::disk(self::DISCO)->delete($ruta);
        } catch (RuntimeException $excepcion) {
            Log::channel('admin')->warning('No se pudo borrar una imagen de fondo', [
                'ruta' => $ruta,
                'error' => $excepcion->getMessage(),
            ]);
        }
    }

    /**
     * Borra la carpeta entera de un fondo. Solo se llama al eliminar un fondo
     * en borrador y sin donaciones; un fondo con historial no se borra nunca.
     */
    public function eliminarCarpeta(Fondo $fondo): void
    {
        Storage::disk(self::DISCO)->deleteDirectory($this->carpetaDe($fondo));
    }

    /** URL pública de una imagen guardada, o null si no hay ninguna. */
    public function url(?string $rutaRelativa): ?string
    {
        $ruta = trim((string) $rutaRelativa);

        return $ruta !== '' ? Storage::disk(self::DISCO)->url($ruta) : null;
    }

    private function carpetaDe(Fondo $fondo): string
    {
        return (string) $fondo->getKey();
    }
}
