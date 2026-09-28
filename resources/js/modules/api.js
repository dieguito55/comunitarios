/**
 * Utilidades compartidas por los módulos del sitio público.
 *
 * Aquí vive todo lo que los tres módulos necesitan por igual, para que ninguno
 * reimplemente a su manera algo tan delicado como escapar HTML.
 */

/**
 * Escapa TODO lo que venga del servidor antes de insertarlo con innerHTML.
 *
 * El nombre de un donante es texto que escribió una persona: si se inyecta sin
 * escapar, quien done puede ejecutar JavaScript en el navegador de cualquiera
 * que mire la portada.
 */
export function escaparHtml(valor) {
    return String(valor ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/** Entero positivo, o 0 si no lo es. Nunca lanza. */
export function enteroPositivo(valor) {
    const crudo = String(valor ?? '').trim();

    return /^\d+$/.test(crudo) ? Number(crudo) : 0;
}

/**
 * POST JSON contra nuestra API.
 *
 * Devuelve SIEMPRE `{ ok, estado, datos }` en vez de lanzar, para que quien
 * llame no tenga que envolver cada petición en su propio try/catch y se olvide
 * en alguna.
 */
export async function enviarJson(url, cuerpo) {
    try {
        const respuesta = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(cuerpo),
        });

        const texto = await respuesta.text();
        let datos = null;

        try {
            datos = texto ? JSON.parse(texto) : null;
        } catch (error) {
            datos = null;
        }

        return { ok: respuesta.ok, estado: respuesta.status, datos };
    } catch (error) {
        // Sin conexión o petición abortada.
        return { ok: false, estado: 0, datos: null };
    }
}

/** GET JSON. Mismo contrato que enviarJson. */
export async function leerJson(url) {
    try {
        const respuesta = await fetch(url, {
            method: 'GET',
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!respuesta.ok) {
            return { ok: false, estado: respuesta.status, datos: null };
        }

        return { ok: true, estado: respuesta.status, datos: await respuesta.json() };
    } catch (error) {
        return { ok: false, estado: 0, datos: null };
    }
}

/**
 * localStorage que nunca rompe nada.
 *
 * En navegación privada y con las cookies bloqueadas, tocar localStorage
 * LANZA. Y perder el respaldo de la reconciliación no puede impedir una
 * donación: la red 2 también funciona con los parámetros de la URL.
 */
export const almacen = {
    CLAVE: 'comunitarios:donacion',

    guardar(datos) {
        try {
            localStorage.setItem(this.CLAVE, JSON.stringify(datos));

            return true;
        } catch (error) {
            return false;
        }
    },

    leer() {
        try {
            const crudo = localStorage.getItem(this.CLAVE);

            return crudo ? JSON.parse(crudo) : null;
        } catch (error) {
            return null;
        }
    },

    olvidar() {
        try {
            localStorage.removeItem(this.CLAVE);
        } catch (error) {
            // Da igual: si no se puede borrar, tampoco se pudo guardar.
        }
    },
};

/** Si la persona pidió que no haya animaciones, no las hay. */
export function prefiereMenosMovimiento() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Formatea un importe con la moneda, en español de Perú. */
export function formatearMonto(monto, moneda = 'PEN') {
    const numero = Number(monto) || 0;

    try {
        return new Intl.NumberFormat('es-PE', {
            style: 'currency',
            currency: moneda,
            minimumFractionDigits: 2,
        }).format(numero);
    } catch (error) {
        return `${moneda} ${numero.toFixed(2)}`;
    }
}
