import { almacen, enteroPositivo, enviarJson, escaparHtml, formatearMonto } from './api.js';
import { pintarIconos } from './iconos.js';

/**
 * Pantalla de vuelta del checkout. Es la SEGUNDA RED DE SEGURIDAD.
 *
 * El webhook falla más de lo que parece —se cae el servidor, Mercado Pago no
 * llega, la URL pública cambió—, y esta pantalla atrapa lo que aquel pierde.
 *
 * ⚠️ LA REGLA QUE NO SE PUEDE ROMPER: el navegador NUNCA afirma un estado.
 *
 * Mercado Pago devuelve al donante con `?status=approved` en la URL, pero esa
 * URL la puede escribir cualquiera. Este módulo usa los parámetros SOLO para
 * saber QUÉ preguntarle al servidor; quien decide es el servidor, consultando
 * la API de Mercado Pago con nuestro token. Por eso `status` ni se lee.
 *
 * El Blade ya dejó pintado un mensaje provisional según `?donacion=`, así que
 * la pantalla es útil aunque este archivo no llegue a ejecutarse.
 *
 * ⚠️ Y NADA DE ENSEÑAR «APROBADO» ANTES DE TIEMPO. El estado de espera es un
 * estado propio, con su texto; no una versión descolorida del aprobado.
 */

const RUTA_RECONCILIAR = '/api/donaciones/reconciliar';
const REINTENTOS = 5;
const ESPERA_MS = 3000;

export function iniciarResultado() {
    const panel = document.querySelector('[data-resultado]');

    if (!panel) {
        return;
    }

    // Antes de preguntar nada, la pantalla dice lo que está pasando. Sin esto,
    // los primeros segundos son un mensaje provisional que no explica que hay
    // una consulta en marcha.
    pintar(panel, 'esperando');

    confirmar(panel, 0);
}

async function confirmar(panel, intento) {
    const identificadores = reunirIdentificadores();

    if (!identificadores) {
        // Sin ninguna llave no hay nada que preguntar. No es un error técnico
        // que haya que enseñarle a nadie: es una operación que no podemos
        // identificar, y para eso está el contacto.
        pintar(panel, 'sin_identificar');

        return;
    }

    const { ok, datos } = await enviarJson(RUTA_RECONCILIAR, identificadores);

    if (!ok || !datos || datos.success !== true) {
        // Si esto falla, el webhook resolverá por su cuenta. No se le dice al
        // donante que algo se rompió: su pago probablemente esté bien.
        pintar(panel, 'sin_respuesta');

        return;
    }

    const estado = String(datos.estado || 'pendiente');

    if (estado === 'aprobado' || estado === 'rechazado') {
        pintar(panel, estado);

        // Caso cerrado: el respaldo ya no hace falta en el navegador.
        almacen.olvidar();

        return;
    }

    // En proceso: Mercado Pago tarda en acreditar algunos medios de pago.
    if (intento + 1 < REINTENTOS) {
        // Se sigue esperando, y se dice. El texto cambia a partir del segundo
        // intento para que no parezca colgado.
        pintar(panel, intento === 0 ? 'esperando' : 'esperando_mas');
        window.setTimeout(() => confirmar(panel, intento + 1), ESPERA_MS);

        return;
    }

    pintar(panel, 'en_proceso_agotado');
    almacen.olvidar();
}

/**
 * Las llaves, en orden de fiabilidad:
 *
 *   1. `payment_id` de la URL          — lo más directo
 *   2. `external_reference` de la URL  — es el id de la donación
 *   3. `preference_id` de la URL
 *   4. lo guardado antes de redirigir  — respaldo si MP no volvió con nada
 */
function reunirIdentificadores() {
    const parametros = new URLSearchParams(window.location.search);
    const guardado = almacen.leer() || {};

    const pago = enteroPositivo(parametros.get('payment_id') || parametros.get('collection_id'));
    const donacion = enteroPositivo(parametros.get('external_reference')) || enteroPositivo(guardado.donacion_id);
    const preferencia = String(parametros.get('preference_id') || guardado.preference_id || '').trim();

    if (!pago && !donacion && !preferencia) {
        return null;
    }

    return {
        payment_id: pago || null,
        donacion_id: donacion || null,
        preference_id: preferencia || null,
    };
}

/* ── Los desenlaces ───────────────────────────────────────────────────────── */

/**
 * Qué se le dice a quien acaba de pagar, en cada caso.
 *
 * ── LO QUE MANDA EN ESTOS TEXTOS ────────────────────────────────────────────
 *
 * Cada uno responde a la pregunta que esa persona se está haciendo en ese
 * momento, no a la que nos resulta cómodo responder:
 *
 *   esperando       «¿está pasando algo?»      se está consultando, tarda segundos
 *   aprobado        «¿y ahora qué?»            ya suma, y el comprobante lo manda MP
 *   en proceso      «¿pagué o no pagué?»       puede llegar igual, NO vuelvas a pagar
 *   rechazado       «¿me han cobrado?»         no se te cobró nada, y hay otra vía
 *   sin respuesta   «¿se perdió mi dinero?»    está registrada, aquí va tu referencia
 *   sin identificar nada malo ha pasado        sin dar a entender que falló algo
 *
 * Ninguno promete un correo NUESTRO: el sistema no envía ninguno. El
 * comprobante lo emite y lo manda Mercado Pago.
 *
 * `datos` viene de lo que el navegador guardó ANTES de salir hacia el checkout
 * —monto, moneda, fondo y el identificador de la donación—. No se piden al
 * servidor porque el endpoint de reconciliación es público y sin sesión:
 * devolver ahí el monto o el fondo permitiría enumerar donaciones ajenas
 * probando identificadores. Aquí son datos de quien mira, sobre su donación.
 */
function textosDe(estado, panel, datos) {
    const contacto = panel.dataset.contacto || '';
    const importe = Number(datos.monto) > 0 ? formatearMonto(Number(datos.monto), datos.moneda || 'PEN') : '';
    const fondo = String(datos.fondo || '').trim();
    const referencia = Number(datos.donacion_id) > 0 ? '#' + Number(datos.donacion_id) : '';

    const irAlInicio = { texto: 'Volver al inicio', url: '/', tono: 'ghost' };
    const verProyecto = fondo && datos.fondo_slug
        ? { texto: 'Ver el proyecto', url: '/fondos/' + datos.fondo_slug, tono: 'ghost', icono: 'arrow-right' }
        : null;

    const enProceso = {
        etiqueta: 'En proceso',
        clase: 'estado--en_proceso',
        tono: 'en_proceso',
        icono: 'clock',
        titulo: 'Tu pago se está procesando',
        cuerpo: 'Mercado Pago todavía no confirmó la operación, y es normal: con tarjeta suele '
            + 'resolverse en minutos, y los pagos en efectivo o por transferencia pueden tardar '
            + 'hasta dos días hábiles.',
        detalle: 'El dinero puede llegar igualmente, así que no vuelvas a pagar. Si se confirma, '
            + 'tu aporte aparecerá solo en el contador, sin que tengas que hacer nada.',
        acciones: [verProyecto, irAlInicio],
    };

    const mapa = {
        // ── La espera ───────────────────────────────────────────────────────
        esperando: {
            etiqueta: 'Confirmando',
            clase: 'estado--en_proceso',
            tono: 'en_proceso',
            icono: 'loader-circle',
            esperando: true,
            titulo: 'Estamos confirmando tu pago con Mercado Pago…',
            cuerpo: 'Suele tardar unos segundos.',
            detalle: 'No cierres esta página ni vuelvas a pagar: tu operación ya está registrada.',
            acciones: [],
        },

        esperando_mas: {
            etiqueta: 'Confirmando',
            clase: 'estado--en_proceso',
            tono: 'en_proceso',
            icono: 'loader-circle',
            esperando: true,
            titulo: 'Seguimos confirmando tu pago…',
            cuerpo: 'Está tardando un poco más de lo habitual. Seguimos preguntándole a Mercado Pago.',
            detalle: 'No hace falta que hagas nada. Tu operación está registrada.',
            acciones: [],
        },

        // ── Los cinco desenlaces ────────────────────────────────────────────
        aprobado: {
            etiqueta: 'Aprobado',
            clase: 'estado--aprobado',
            tono: 'aprobado',
            icono: 'circle-check',
            titulo: '¡Gracias! Tu aporte está confirmado',
            cuerpo: importe && fondo
                ? 'Se registraron ' + importe + ' para ' + fondo + '. Ese importe ya suma en el '
                    + 'contador público de la campaña.'
                : 'Tu donación quedó confirmada y ya suma en el contador público de la campaña.',
            detalle: 'El comprobante del pago lo emite y lo envía Mercado Pago al correo con el que pagaste.',
            acciones: [
                verProyecto,
                { texto: 'Compartir la campaña', url: '', tono: 'coral', icono: 'share-2', compartir: true },
                irAlInicio,
            ],
        },

        en_proceso: enProceso,
        pendiente: enProceso,

        en_proceso_agotado: Object.assign({}, enProceso, {
            titulo: 'Tu pago sigue en revisión',
            cuerpo: 'Dejamos de consultar por ahora, pero la operación sigue su curso en Mercado Pago. '
                + 'Algunos medios de pago tardan en acreditarse.',
        }),

        rechazado: {
            etiqueta: 'Rechazado',
            clase: 'estado--rechazado',
            tono: 'rechazado',
            icono: 'circle-x',
            titulo: 'El banco no autorizó el cargo',
            cuerpo: 'No se te cobró nada. Suele pasar por el límite de la tarjeta, por algún dato que '
                + 'no coincide, o porque el banco bloquea las compras por internet.',
            detalle: 'Puedes intentarlo con otra tarjeta, o pagar con Yape o Plin, que no pasa por el banco.'
                + (contacto ? ' Si se repite, escríbenos a ' + contacto + '.' : ''),
            acciones: [
                { texto: 'Intentar de nuevo', url: '/donar', tono: 'coral', icono: 'rotate-ccw' },
                { texto: 'Pagar con Yape o Plin', url: '/donar', tono: 'ghost', icono: 'smartphone' },
                irAlInicio,
            ],
        },

        sin_respuesta: {
            etiqueta: 'Sin confirmar',
            clase: '',
            tono: '',
            icono: 'search',
            titulo: 'No pudimos confirmarlo ahora mismo',
            cuerpo: 'Tu donación está registrada. Lo que no conseguimos fue preguntarle a Mercado Pago '
                + 'cómo quedó; se confirma sola en cuanto nos lo comunique.',
            detalle: referencia
                ? 'Si quieres asegurarte, escríbenos' + (contacto ? ' a ' + contacto : '')
                    + ' citando esta referencia: ' + referencia + '.'
                : 'Si quieres asegurarte, escríbenos' + (contacto ? ' a ' + contacto : '') + ' y lo revisamos.',
            acciones: [irAlInicio],
        },

        sin_identificar: {
            etiqueta: '',
            clase: '',
            tono: '',
            icono: 'search',
            titulo: 'No hay ninguna operación que consultar',
            cuerpo: 'Llegaste a esta página sin los datos de una donación. No ha fallado nada: '
                + 'simplemente no hay nada que comprobar.',
            detalle: contacto
                ? 'Si acabas de donar y no la ves reflejada, escríbenos a ' + contacto + ' y la buscamos.'
                : 'Si acabas de donar y no la ves reflejada, escríbenos y la buscamos.',
            acciones: [
                { texto: 'Ir a donar', url: '/donar', tono: 'coral', icono: 'heart-handshake' },
                irAlInicio,
            ],
        },
    };

    return mapa[estado] || mapa.sin_respuesta;
}

/**
 * Pinta el estado. Emite CLASES, nunca colores: el CSS decide cómo se ve un
 * `estado--aprobado`.
 */
function pintar(panel, estado) {
    const datos = almacen.leer() || {};
    const texto = textosDe(estado, panel, datos);

    escribir(panel.querySelector('[data-resultado-titulo]'), texto.titulo);
    escribir(panel.querySelector('[data-resultado-cuerpo]'), texto.cuerpo);
    escribir(panel.querySelector('[data-resultado-detalle]'), texto.detalle || '');

    const insignia = panel.querySelector('[data-resultado-estado]');

    if (insignia) {
        insignia.className = texto.clase ? 'estado ' + texto.clase : 'estado';
        insignia.textContent = texto.etiqueta;
        insignia.hidden = texto.etiqueta === '';
    }

    // El contenedor lleva el tono: el CSS decide de qué color es el orbe. Aquí
    // solo se emiten clases, nunca un color.
    panel.className = ['don-resultado']
        .concat(texto.tono ? ['don-resultado--' + texto.tono] : [])
        .concat(texto.esperando ? ['don-resultado--esperando'] : [])
        .join(' ');

    const orbe = panel.querySelector('[data-resultado-icono]');

    if (orbe && texto.icono) {
        orbe.innerHTML = '<i data-lucide="' + escaparHtml(texto.icono) + '"></i>';
    }

    pintarAcciones(panel, texto.acciones || [], datos);

    // El esqueleto solo tiene sentido mientras el estado sigue en el aire.
    const cargando = panel.querySelector('[data-resultado-cargando]');

    if (cargando) {
        cargando.hidden = ! texto.esperando;
    }

    pintarIconos();
}

/**
 * Las salidas de cada desenlace.
 *
 * Se pintan desde JavaScript porque dependen del estado REAL, que el servidor
 * no conoce cuando renderiza la página: en ese momento solo tiene el
 * `?donacion=` de la URL, que no decide nada.
 */
function pintarAcciones(panel, acciones, datos) {
    const zona = panel.querySelector('[data-resultado-acciones]');

    if (!zona) {
        return;
    }

    const utiles = acciones.filter(Boolean);

    zona.innerHTML = utiles
        .map((accion) => {
            const clase = accion.tono === 'coral' ? 'button button-coral' : 'button button-ghost';
            const icono = accion.icono ? ' <i data-lucide="' + escaparHtml(accion.icono) + '"></i>' : '';

            if (accion.compartir) {
                return '<button type="button" class="' + clase + '" data-resultado-compartir>'
                    + escaparHtml(accion.texto) + icono + '</button>';
            }

            return '<a class="' + clase + '" href="' + escaparHtml(accion.url) + '">'
                + escaparHtml(accion.texto) + icono + '</a>';
        })
        .join('');

    zona.hidden = utiles.length === 0;

    const compartir = zona.querySelector('[data-resultado-compartir]');

    if (compartir) {
        compartir.addEventListener('click', () => compartirCampana(compartir, datos));
    }
}

/**
 * Compartir la campaña.
 *
 * Con la API nativa donde exista —en el móvil abre el menú del sistema— y con
 * el portapapeles donde no. Sin botones de redes sociales: cargan rastreadores
 * de terceros en una página que acaba de ver un pago.
 */
async function compartirCampana(boton, datos) {
    const url = datos.fondo_slug
        ? window.location.origin + '/fondos/' + datos.fondo_slug
        : window.location.origin;

    const texto = datos.fondo
        ? 'Acabo de aportar a ' + datos.fondo + ', de la Fundación Territorial Comunitarios.'
        : 'Acabo de aportar a la Fundación Territorial Comunitarios.';

    if (navigator.share) {
        try {
            await navigator.share({ title: 'Comunitarios', text: texto, url });
        } catch (error) {
            // Cancelar el menú de compartir no es un fallo: no se dice nada.
        }

        return;
    }

    try {
        await navigator.clipboard.writeText(url);
    } catch (error) {
        return;
    }

    const original = boton.innerHTML;

    boton.textContent = 'Enlace copiado';
    window.setTimeout(() => {
        boton.innerHTML = original;
        pintarIconos();
    }, 1800);
}

function escribir(nodo, texto) {
    if (!nodo) {
        return;
    }

    // textContent, no innerHTML: estos textos son nuestros y no llevan
    // marcado, así que no hay razón para interpretar nada.
    nodo.textContent = texto;
    nodo.hidden = texto === '';
}
