import { escaparHtml, formatearMonto } from './api.js';
import { pintarIconos } from './iconos.js';

/**
 * Canal QR: Yape y Plin.
 *
 * ── LO QUE ESTE MÓDULO NO HACE ──────────────────────────────────────────────
 *
 * No confirma ningún pago. Sube un comprobante y se acabó. Quien confirma es
 * una persona del equipo mirando la captura en el panel, y por eso el mensaje
 * de éxito dice «recibimos tu comprobante», nunca «gracias por tu donación».
 * Decir lo segundo sería afirmar que entró un dinero que nadie ha visto.
 *
 * ── SIN JAVASCRIPT ──────────────────────────────────────────────────────────
 *
 * El bloque entero viene renderizado del servidor y nace visible. Este módulo
 * lo oculta al arrancar para que el selector de canal tenga sentido; si no se
 * ejecuta, se ven los dos canales a la vez —menos elegante, igual de usable—
 * en lugar de un canal invisible.
 */

const RUTA_QR = '/api/donaciones/qr';

export function iniciarQr() {
    const formulario = document.querySelector('[data-donacion-formulario]');
    const bloqueQr = document.querySelector('[data-qr-bloque]');

    if (!formulario || !bloqueQr) {
        return;
    }

    const campoMonto = formulario.querySelector('#donacion-monto');

    configurarCanales(formulario);
    reflejarMonto(formulario, campoMonto);
    configurarCopiar(formulario);
    configurarVistaPrevia(formulario);
    configurarEnvio(formulario);
}

/* ── Selector de canal ───────────────────────────────────────────────────── */

function configurarCanales(formulario) {
    const botones = formulario.querySelectorAll('[data-canal]');
    const bloques = formulario.querySelectorAll('[data-canal-bloque]');
    const bloqueQr = formulario.querySelector('[data-qr-bloque]');

    if (botones.length === 0) {
        return;
    }

    const mostrar = (canal) => {
        botones.forEach((boton) => {
            boton.setAttribute('aria-pressed', String(boton.dataset.canal === canal));
        });

        bloques.forEach((bloque) => {
            bloque.hidden = bloque.dataset.canalBloque !== canal;
        });

        if (bloqueQr) {
            bloqueQr.hidden = canal !== 'qr';
        }
    };

    botones.forEach((boton) => {
        boton.addEventListener('click', () => mostrar(boton.dataset.canal));
    });

    // Estado inicial: tarjeta. Aquí es donde el bloque QR pasa a estar oculto.
    mostrar('tarjeta');
}

/* ── El monto a transferir ───────────────────────────────────────────────── */

/**
 * El importe del QR es el mismo que se escribió arriba.
 *
 * Que aparezca grande y repetido no es decoración: quien está a punto de
 * yapear tiene que teclearlo en otra app, y volver a subir para mirarlo es
 * justo cuando se equivoca uno de dígito.
 */
function reflejarMonto(formulario, campoMonto) {
    const destino = formulario.querySelector('[data-qr-monto]');

    if (!destino || !campoMonto) {
        return;
    }

    const moneda = formulario.dataset.moneda || 'PEN';

    const refrescar = () => {
        const monto = Number(String(campoMonto.value).replace(',', '.')) || 0;

        destino.textContent = formatearMonto(monto, moneda);
        destino.dataset.montoPlano = monto > 0 ? monto.toFixed(2) : '';
    };

    campoMonto.addEventListener('input', refrescar);
    refrescar();
}

function configurarCopiar(formulario) {
    const boton = formulario.querySelector('[data-qr-copiar]');
    const monto = formulario.querySelector('[data-qr-monto]');

    if (!boton || !monto) {
        return;
    }

    boton.addEventListener('click', async () => {
        const plano = monto.dataset.montoPlano || '';

        if (plano === '') {
            return;
        }

        try {
            await navigator.clipboard.writeText(plano);
        } catch (error) {
            // Sin permiso de portapapeles no se puede hacer nada mejor, y el
            // importe está a la vista: se puede copiar a mano.
            return;
        }

        const original = boton.innerHTML;

        boton.textContent = boton.dataset.copiado || 'Copiado';
        window.setTimeout(() => {
            boton.innerHTML = original;
            pintarIconos();
        }, 1600);
    });
}

/* ── Vista previa del comprobante ────────────────────────────────────────── */

/**
 * Enseña lo que se va a enviar ANTES de enviarlo.
 *
 * Adjuntar la captura equivocada es el error más común de este flujo, y desde
 * el lado del donante es invisible: el nombre del archivo no dice nada. Verla
 * lo resuelve.
 */
function configurarVistaPrevia(formulario) {
    const campo = formulario.querySelector('#qr-comprobante');
    const previa = formulario.querySelector('[data-qr-previa]');

    if (!campo || !previa) {
        return;
    }

    const imagen = previa.querySelector('[data-qr-previa-imagen]');
    const texto = previa.querySelector('[data-qr-previa-texto]');
    const nombre = formulario.querySelector('[data-qr-nombre]');

    campo.addEventListener('change', () => {
        const archivo = campo.files && campo.files[0];

        if (imagen.src) {
            URL.revokeObjectURL(imagen.src);
        }

        if (!archivo) {
            previa.hidden = true;
            imagen.removeAttribute('src');

            if (nombre) {
                nombre.textContent = 'Elegir imagen o PDF';
            }

            return;
        }

        const peso = (archivo.size / (1024 * 1024)).toFixed(1);

        if (nombre) {
            nombre.textContent = archivo.name;
        }

        // Un PDF no se puede previsualizar sin un visor: se dice qué es y
        // cuánto pesa, que es la información útil que queda.
        if (archivo.type === 'application/pdf') {
            previa.hidden = false;
            imagen.hidden = true;
            texto.textContent = `${archivo.name} · PDF · ${peso} MB`;

            return;
        }

        previa.hidden = false;
        imagen.hidden = false;
        imagen.src = URL.createObjectURL(archivo);
        texto.textContent = `${archivo.name} · ${peso} MB`;
    });
}

/* ── Envío ───────────────────────────────────────────────────────────────── */

function configurarEnvio(formulario) {
    const boton = formulario.querySelector('[data-qr-enviar]');
    const aviso = formulario.querySelector('[data-donacion-aviso]');

    if (!boton) {
        return;
    }

    boton.addEventListener('click', async () => {
        limpiarErrores(formulario);

        const datos = new FormData(formulario);

        // El formulario comparte los campos del donante con el canal de
        // tarjeta. Lo que sobra aquí es la casilla de anonimato, que viaja con
        // otro nombre: el servidor espera `visible_publico`.
        datos.delete('anonimo');
        datos.set('visible_publico', formulario.elements.anonimo?.checked ? '0' : '1');

        bloquear(boton, true);

        let respuesta;
        let estado = 0;

        try {
            const peticion = await fetch(RUTA_QR, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: datos,
            });

            estado = peticion.status;
            respuesta = await peticion.json();
        } catch (error) {
            bloquear(boton, false);
            mostrar(aviso, 'No pudimos enviar tu comprobante. Revisa tu conexión e inténtalo otra vez.', 'error');

            return;
        }

        bloquear(boton, false);

        if (estado === 201 && respuesta && respuesta.success === true) {
            pintarRecibido(formulario, respuesta.mensaje);

            return;
        }

        if (estado === 422 && respuesta && respuesta.campos) {
            marcarCampos(formulario, respuesta.campos);
        }

        mostrar(
            aviso,
            (respuesta && respuesta.error) || 'No pudimos registrar tu comprobante. Inténtalo otra vez.',
            'error'
        );
    });
}

/**
 * Sustituye el formulario por el acuse.
 *
 * NO dice que la donación esté confirmada. Dice que el comprobante llegó y que
 * alguien lo va a mirar, que es exactamente lo que ha ocurrido.
 */
function pintarRecibido(formulario, mensaje) {
    const texto = escaparHtml(
        mensaje || 'Recibimos tu comprobante. Tu aporte aparecerá en el contador cuando quede verificado.'
    );

    formulario.innerHTML = `
        <section class="don-resultado don-resultado--en_proceso">
            <div class="don-resultado__orbe" aria-hidden="true"><i data-lucide="clock"></i></div>
            <span class="estado estado--en_proceso">En verificación</span>
            <h2>Recibimos tu comprobante</h2>
            <p>${texto}</p>
            <div class="don-resultado__acciones">
                <a class="button button-ghost" href="/">Volver al inicio</a>
            </div>
        </section>`;

    pintarIconos();
    formulario.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/* ── Utilidades compartidas con el canal de tarjeta ──────────────────────── */

function bloquear(boton, bloqueado) {
    boton.disabled = bloqueado;

    if (!bloqueado) {
        boton.textContent = boton.dataset.textoOriginal || 'Enviar mi comprobante';
        pintarIconos();

        return;
    }

    boton.innerHTML = '<span class="don-girador" aria-hidden="true"></span> Enviando tu comprobante…';
}

function marcarCampos(formulario, campos) {
    Object.keys(campos).forEach((nombre) => {
        const contenedor = formulario.querySelector(`[data-campo="${nombre}"]`);

        if (!contenedor) {
            return;
        }

        contenedor.classList.add('don-campo--error');

        const hueco = contenedor.querySelector('[data-campo-error]');

        if (hueco) {
            hueco.textContent = campos[nombre];
        }
    });
}

function limpiarErrores(formulario) {
    formulario.querySelectorAll('.don-campo--error').forEach((contenedor) => {
        contenedor.classList.remove('don-campo--error');
        const hueco = contenedor.querySelector('[data-campo-error]');
        if (hueco) {
            hueco.textContent = '';
        }
    });
}

function mostrar(nodo, mensaje, tono) {
    if (!nodo) {
        return;
    }

    nodo.className = 'don-aviso' + (tono ? ` don-aviso--${tono}` : '');
    nodo.textContent = mensaje;
    nodo.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
