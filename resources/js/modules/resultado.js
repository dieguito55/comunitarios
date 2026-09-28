import { almacen, enteroPositivo, enviarJson } from './api.js';

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
 */

const RUTA_RECONCILIAR = '/api/donaciones/reconciliar';
const REINTENTOS = 5;
const ESPERA_MS = 3000;

export function iniciarResultado() {
    const panel = document.querySelector('[data-resultado]');

    if (!panel) {
        return;
    }

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

    pintar(panel, estado);

    if (estado === 'aprobado' || estado === 'rechazado') {
        // Caso cerrado: el respaldo ya no hace falta.
        almacen.olvidar();

        return;
    }

    // En proceso: Mercado Pago tarda en acreditar algunos medios de pago.
    if (intento + 1 < REINTENTOS) {
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

/**
 * Pinta el estado. Emite CLASES, nunca colores: el CSS decide cómo se ve un
 * `estado--aprobado`.
 */
function pintar(panel, estado) {
    const titulo = panel.querySelector('[data-resultado-titulo]');
    const cuerpo = panel.querySelector('[data-resultado-cuerpo]');
    const insignia = panel.querySelector('[data-resultado-estado]');
    const acciones = panel.querySelector('[data-resultado-acciones]');
    const contacto = panel.dataset.contacto || '';

    const textos = {
        aprobado: {
            etiqueta: 'Aprobado',
            titulo: '¡Gracias por tu aporte!',
            cuerpo: 'Tu donación quedó confirmada. El comprobante del pago lo emite Mercado Pago y te lo envía por su cuenta.',
            clase: 'estado--aprobado',
        },
        en_proceso: {
            etiqueta: 'En proceso',
            titulo: 'Estamos confirmando tu pago',
            cuerpo: 'Mercado Pago todavía no nos confirmó la operación. Esta página se actualiza sola; no hace falta que hagas nada.',
            clase: 'estado--en_proceso',
        },
        pendiente: {
            etiqueta: 'Pendiente',
            titulo: 'Estamos confirmando tu pago',
            cuerpo: 'Mercado Pago todavía no nos confirmó la operación. Esta página se actualiza sola; no hace falta que hagas nada.',
            clase: 'estado--pendiente',
        },
        en_proceso_agotado: {
            etiqueta: 'En proceso',
            titulo: 'Tu pago sigue en revisión',
            cuerpo: 'Algunos medios de pago tardan en acreditarse. Mercado Pago te avisará en cuanto la operación termine de procesarse. No hace falta que vuelvas a donar: tu aporte se registra solo.',
            clase: 'estado--en_proceso',
        },
        rechazado: {
            etiqueta: 'Rechazado',
            titulo: 'El pago no se completó',
            cuerpo: 'Mercado Pago rechazó la operación, normalmente por un problema con la tarjeta o con los datos. No se te cobró nada; puedes intentarlo otra vez.',
            clase: 'estado--rechazado',
        },
        sin_identificar: {
            etiqueta: '',
            titulo: 'No pudimos identificar la operación',
            cuerpo: contacto
                ? `Si hiciste una donación y no la ves reflejada, escríbenos a ${contacto} y la revisamos.`
                : 'Si hiciste una donación y no la ves reflejada, escríbenos y la revisamos.',
            clase: '',
        },
        sin_respuesta: {
            etiqueta: '',
            titulo: 'Estamos confirmando tu pago',
            cuerpo: 'No pudimos comprobarlo ahora mismo, pero tu donación no se ha perdido: se confirma sola en cuanto Mercado Pago nos comunique el resultado.',
            clase: '',
        },
    };

    const texto = textos[estado] || textos.sin_respuesta;

    if (titulo) {
        titulo.textContent = texto.titulo;
    }

    if (cuerpo) {
        // textContent, no innerHTML: estos textos son nuestros y no llevan
        // marcado, así que no hay razón para interpretar nada.
        cuerpo.textContent = texto.cuerpo;
    }

    if (insignia) {
        insignia.className = texto.clase ? `estado ${texto.clase}` : '';
        insignia.textContent = texto.etiqueta;
        insignia.hidden = texto.etiqueta === '';
    }

    if (acciones) {
        acciones.hidden = estado !== 'rechazado' && estado !== 'sin_identificar';
    }
}
