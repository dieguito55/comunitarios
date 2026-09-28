import { escaparHtml, formatearMonto, leerJson, prefiereMenosMovimiento } from './api.js';

/**
 * Dashboard en vivo: cifras de la campaña y feed de donantes.
 *
 * Tres decisiones deliberadas:
 *
 *  1. **Si la petición falla, no se toca nada.** Se deja lo último que había.
 *     Enseñar ceros o un error donde antes había cifras reales parece que la
 *     campaña se cayó, y no es verdad.
 *
 *  2. **Solo se anima la primera carga.** Animar en cada refresco de 30 s hace
 *     que los números parpadeen constantemente y molesta al leer.
 *
 *  3. **`prefers-reduced-motion` manda.** Quien pidió que no haya movimiento
 *     ve el valor final directamente.
 *
 * Todo lo que viene del servidor pasa por escaparHtml: el nombre de un donante
 * lo escribió una persona.
 */

const RUTA_DASHBOARD = '/api/dashboard';
const REFRESCO_MS = 30000;

let primeraCarga = true;

export function iniciarDashboard() {
    const panel = document.querySelector('[data-dashboard]');

    if (!panel) {
        return;
    }

    actualizar(panel);
    window.setInterval(() => actualizar(panel), REFRESCO_MS);
}

async function actualizar(panel) {
    const { ok, datos } = await leerJson(RUTA_DASHBOARD);

    if (!ok || !datos || datos.success !== true) {
        return;   // Decisión 1: se deja lo que ya estaba.
    }

    pintarTotales(panel, datos.totales || {});
    pintarFondos(panel, datos.fondos || []);
    pintarFeed(panel, datos.recientes || []);

    primeraCarga = false;
}

function pintarTotales(panel, totales) {
    const moneda = totales.moneda || 'PEN';

    animarCifra(panel.querySelector('[data-total-recaudado]'), Number(totales.recaudado) || 0, moneda);
    animarEntero(panel.querySelector('[data-total-donaciones]'), Number(totales.donaciones) || 0);
    animarEntero(panel.querySelector('[data-total-donantes]'), Number(totales.donantes_unicos) || 0);
}

function pintarFondos(panel, fondos) {
    fondos.forEach((fondo) => {
        const tarjeta = panel.querySelector(`[data-fondo-slug="${CSS.escape(fondo.slug)}"]`);

        if (!tarjeta) {
            return;
        }

        escribir(tarjeta.querySelector('[data-fondo-recaudado]'), formatearMonto(fondo.recaudado, fondo.moneda));
        escribir(tarjeta.querySelector('[data-fondo-donaciones]'), String(fondo.donaciones ?? 0));

        const barra = tarjeta.querySelector('[data-fondo-progreso]');

        if (!barra) {
            return;
        }

        // Sin meta no hay barra. Nunca un 0 %, que parecería un fracaso.
        if (fondo.porcentaje === null || fondo.porcentaje === undefined) {
            barra.hidden = true;

            return;
        }

        const porcentaje = Math.max(0, Math.min(100, Number(fondo.porcentaje) || 0));

        barra.hidden = false;
        barra.setAttribute('aria-valuenow', String(Math.round(porcentaje)));

        const relleno = barra.querySelector('[data-fondo-progreso-relleno]');

        if (relleno) {
            relleno.style.width = `${porcentaje}%`;
        }
    });
}

function pintarFeed(panel, recientes) {
    const lista = panel.querySelector('[data-feed]');

    if (!lista) {
        return;
    }

    if (recientes.length === 0) {
        lista.innerHTML = '<li class="don-feed__vacio">Todavía no hay donaciones. Puedes ser la primera persona en aportar.</li>';

        return;
    }

    lista.innerHTML = recientes
        .map((donacion) => {
            // El servidor ya decidió qué nombre sale: si es anónimo, el real
            // nunca llegó hasta aquí.
            const nombre = escaparHtml(donacion.nombre);
            const cuando = escaparHtml(donacion.hace);
            const monto = escaparHtml(formatearMonto(donacion.monto));

            return `<li>
                <span><strong>${nombre}</strong> <span class="don-feed__cuando">${cuando}</span></span>
                <span class="don-feed__monto">${monto}</span>
            </li>`;
        })
        .join('');
}

/**
 * Cuenta ascendente para los totales que son numeros enteros.
 *
 * Misma regla que animarCifra: solo en la primera carga, y nunca si se pidio
 * reducir el movimiento. En los refrescos de cada 30 s el numero cambia de
 * golpe, que es lo correcto —animar cada actualizacion haria parpadear el
 * panel sin parar.
 */
function animarEntero(nodo, destino) {
    if (!nodo) {
        return;
    }

    if (!primeraCarga || prefiereMenosMovimiento() || destino <= 0) {
        nodo.textContent = String(destino);

        return;
    }

    const duracion = 800;
    const inicio = performance.now();

    const paso = (ahora) => {
        const avance = Math.min(1, (ahora - inicio) / duracion);
        const suavizado = 1 - Math.pow(1 - avance, 3);   // easeOutCubic

        nodo.textContent = String(Math.round(destino * suavizado));

        if (avance < 1) {
            window.requestAnimationFrame(paso);
        }
    };

    window.requestAnimationFrame(paso);
}

function escribir(nodo, texto) {
    if (nodo) {
        nodo.textContent = texto;
    }
}

/**
 * Cuenta ascendente con desaceleración, solo en la primera carga y solo si no
 * se pidió reducir el movimiento.
 */
function animarCifra(nodo, destino, moneda) {
    if (!nodo) {
        return;
    }

    if (!primeraCarga || prefiereMenosMovimiento()) {
        nodo.textContent = formatearMonto(destino, moneda);

        return;
    }

    const duracion = 900;
    const inicio = performance.now();

    const paso = (ahora) => {
        const avance = Math.min(1, (ahora - inicio) / duracion);
        const suavizado = 1 - Math.pow(1 - avance, 3);   // easeOutCubic

        nodo.textContent = formatearMonto(destino * suavizado, moneda);

        if (avance < 1) {
            window.requestAnimationFrame(paso);
        }
    };

    window.requestAnimationFrame(paso);
}
