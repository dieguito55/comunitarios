import { prefiereMenosMovimiento } from './api.js';

/**
 * Animaciones de entrada: barras de progreso y contadores.
 *
 * DOS REGLAS QUE MANDAN SOBRE EL EFECTO:
 *
 *  1. **El valor correcto ya está en el HTML.** La barra trae su `width` en
 *     línea desde Blade y el contador, su número. Este módulo los pone a cero
 *     y los deja crecer; si el JavaScript no llega a ejecutarse, la página se
 *     ve bien igual. Nunca al revés: una animación no puede ser el único
 *     camino hacia el dato.
 *
 *  2. **`prefers-reduced-motion` corta por lo sano.** Si alguien pidió que no
 *     haya movimiento, este módulo no toca nada y se va. No hay versión
 *     «suavizada»: hay animación o no la hay.
 *
 * Solo se anima `transform`, `opacity` y el `width` de la barra. Ese `width`
 * es la única excepción al «nunca animes propiedades de layout»: la barra está
 * aislada dentro de su pista con `overflow: hidden`, así que el reflujo no sale
 * de ahí, y la alternativa —`scaleX`— deforma los extremos redondeados.
 */

const MARGEN = '0px 0px -10% 0px';

export function iniciarProgreso() {
    if (prefiereMenosMovimiento()) {
        return;
    }

    // Sin IntersectionObserver no hay animación de entrada, y no pasa nada:
    // los valores ya están pintados.
    if (!('IntersectionObserver' in window)) {
        return;
    }

    animarBarras();
    animarContadores();
}

/** Las barras arrancan a cero y crecen hasta el ancho que traían. */
function animarBarras() {
    const rellenos = document.querySelectorAll('.don-progreso__relleno');

    if (rellenos.length === 0) {
        return;
    }

    const observador = new IntersectionObserver((entradas) => {
        entradas.forEach((entrada) => {
            if (!entrada.isIntersecting) {
                return;
            }

            const relleno = entrada.target;

            relleno.style.width = relleno.dataset.anchoDestino || '0%';
            observador.unobserve(relleno);
        });
    }, { rootMargin: MARGEN });

    rellenos.forEach((relleno) => {
        relleno.dataset.anchoDestino = relleno.style.width || '0%';
        relleno.style.width = '0%';
        observador.observe(relleno);
    });
}

/**
 * Cuenta hacia arriba hasta el número que ya estaba escrito.
 *
 * Se respeta el formato original —separadores de miles, símbolo de moneda,
 * decimales— en vez de reconstruirlo: el servidor ya lo formateó según la
 * configuración de la campaña y aquí no tenemos por qué saber cómo.
 */
function animarContadores() {
    // Lo que cuelga de [data-dashboard] lo anima dashboard.js en su primera
    // carga. Dos modulos contando el mismo numero se pisan y el resultado es
    // una cifra que parpadea.
    const contadores = Array.from(document.querySelectorAll('[data-contador]'))
        .filter((nodo) => !nodo.closest('[data-dashboard]'));

    if (contadores.length === 0) {
        return;
    }

    const observador = new IntersectionObserver((entradas) => {
        entradas.forEach((entrada) => {
            if (!entrada.isIntersecting) {
                return;
            }

            contar(entrada.target);
            observador.unobserve(entrada.target);
        });
    }, { rootMargin: MARGEN });

    contadores.forEach((contador) => observador.observe(contador));
}

function contar(nodo) {
    const textoFinal = nodo.textContent.trim();

    // Se localiza el número dentro del texto y se deja todo lo demás igual.
    const coincidencia = textoFinal.match(/([\d.,]+)/);

    if (!coincidencia) {
        return;
    }

    const crudo = coincidencia[1];

    // "1,234.56" → 1234.56. Las comas son separador de miles en es-PE.
    const destino = Number(crudo.replace(/,/g, ''));

    if (!Number.isFinite(destino) || destino <= 0) {
        return;
    }

    const decimales = (crudo.split('.')[1] || '').length;
    const antes = textoFinal.slice(0, coincidencia.index);
    const despues = textoFinal.slice(coincidencia.index + crudo.length);
    const duracion = 800;
    const inicio = performance.now();

    const paso = (ahora) => {
        const avance = Math.min(1, (ahora - inicio) / duracion);
        const suavizado = 1 - Math.pow(1 - avance, 3);   // easeOutCubic
        const valor = destino * suavizado;

        nodo.textContent = antes + valor.toLocaleString('es-PE', {
            minimumFractionDigits: decimales,
            maximumFractionDigits: decimales,
        }) + despues;

        if (avance < 1) {
            window.requestAnimationFrame(paso);

            return;
        }

        // El último fotograma restaura el texto exacto del servidor, para que
        // ningún redondeo de la animación quede como cifra final.
        nodo.textContent = textoFinal;
    };

    window.requestAnimationFrame(paso);
}
