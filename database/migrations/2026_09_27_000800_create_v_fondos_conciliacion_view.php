<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista de conciliación: el contador denormalizado contra la suma real.
 *
 * Si `diferencia` no es 0 en algún fondo, hay un bug de contabilidad. Es la
 * red de seguridad de los contadores: permite detectar el desvío sin confiar
 * en que el código que los mueve sea correcto. La fase 6 la usará en el
 * paquete de auditoría.
 *
 * Se escribe en SQL plano y sin funciones específicas de motor para que valga
 * igual en MariaDB (producción) y en SQLite (la suite de tests).
 */
return new class extends Migration
{
    private const NOMBRE = 'v_fondos_conciliacion';

    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::NOMBRE);

        // La suma se repite en dos columnas a propósito: una vista no puede
        // reutilizar un alias del propio SELECT en otra expresión.
        $sumaReal = "COALESCE(SUM(CASE WHEN d.estado = 'aprobado'
                                       THEN COALESCE(d.monto_real, d.monto_referencial)
                                       ELSE 0 END), 0)";

        DB::statement(
            'CREATE VIEW '.self::NOMBRE.' AS
             SELECT f.id        AS fondo_id,
                    f.slug      AS slug,
                    f.recaudado AS recaudado,
                    '.$sumaReal.' AS total_real,
                    f.recaudado - '.$sumaReal.' AS diferencia,
                    COUNT(CASE WHEN d.estado = \'aprobado\' THEN 1 END) AS donaciones_aprobadas
             FROM fondos f
             LEFT JOIN donaciones d ON d.fondo_id = f.id
             GROUP BY f.id, f.slug, f.recaudado'
        );
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::NOMBRE);
    }
};
