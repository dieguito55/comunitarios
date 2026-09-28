import { almacen, enviarJson, escaparHtml, formatearMonto } from './api.js';

/**
 * Formulario de donación: dos pasos, sin recargar.
 *
 * El formulario YA FUNCIONA sin este archivo —está renderizado en el servidor—
 * y esto solo le añade el paso a paso y la petición sin recarga.
 *
 * Dos cosas que este módulo NO hace, a propósito:
 *
 *  1. **No inventa mensajes de error.** El servidor ya los redacta en español
 *     y con el contexto que el navegador no tiene. Aquí se pintan tal cual
 *     vienen en `error`. Un mensaje distinto en cada capa acaba en dos
 *     verdades sobre lo mismo.
 *
 *  2. **No decide colores.** Emite clases (`don-campo--error`) y el CSS
 *     resuelve el aspecto.
 */

const RUTA_CREAR = '/api/donaciones/mercadopago';

export function iniciarDonacion() {
    const formulario = document.querySelector('[data-donacion-formulario]');

    if (!formulario) {
        return;
    }

    const paso2 = document.querySelector('[data-paso="2"]');
    const botones = formulario.querySelectorAll('[data-donacion-enviar]');
    const avisoGeneral = formulario.querySelector('[data-donacion-aviso]');
    const campoMonto = formulario.querySelector('#donacion-monto');

    configurarSeleccionDeFondo(formulario, paso2);
    configurarMontosSugeridos(formulario, campoMonto);
    configurarResumen(formulario, campoMonto);

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();

        limpiarErrores(formulario, avisoGeneral);

        const datos = recogerDatos(formulario);
        const errorLocal = validarEnElNavegador(datos, formulario);

        // La validación del navegador REFLEJA la del servidor, no la
        // reemplaza: ahorra un viaje, nada más. El servidor vuelve a validarlo
        // todo porque esta comprobación se puede saltar.
        if (errorLocal) {
            mostrarAviso(avisoGeneral, errorLocal, 'error');

            return;
        }

        bloquear(botones, true);

        const { ok, estado, datos: respuesta } = await enviarJson(RUTA_CREAR, datos);

        if (ok && respuesta && respuesta.success === true && respuesta.init_point) {
            // Las dos llaves de la red 2, guardadas ANTES de irnos. Al volver,
            // puede que Mercado Pago no traiga nada en la URL y esto sea lo
            // único que permita saber qué donación era.
            almacen.guardar({
                donacion_id: Number(respuesta.donacion_id) || 0,
                preference_id: String(respuesta.preference_id || ''),
                monto: Number(datos.monto) || 0,
                guardado_en: Date.now(),
            });

            window.location.href = respuesta.init_point;

            return;
        }

        bloquear(botones, false);

        if (estado === 422 && respuesta && Array.isArray(respuesta.errores)) {
            marcarCamposConError(formulario, respuesta);
        }

        mostrarAviso(
            avisoGeneral,
            (respuesta && respuesta.error) || 'No pudimos iniciar el pago. Vuelve a intentarlo en un momento.',
            'error'
        );
    });
}

/**
 * Resumen lateral: el proyecto elegido y el monto, siempre a la vista.
 *
 * Es la unica parte de la pagina que resume lo que esta a punto de pasar. Sin
 * ella hay que recordar de memoria que se eligio arriba mientras se rellenan
 * los datos de abajo, y en el movil el fondo elegido queda fuera de pantalla.
 */
function configurarResumen(formulario, campoMonto) {
    // querySelectorAll y no querySelector: en movil el resumen vive tambien en
    // la barra inferior fija, y los dos tienen que decir lo mismo.
    const nodosFondo = document.querySelectorAll('[data-resumen-fondo]');
    const nodosMonto = document.querySelectorAll('[data-resumen-monto]');
    const moneda = formulario.dataset.moneda || 'PEN';

    const refrescar = () => {
        const elegido = formulario.querySelector('input[name="fondo_id"]:checked');
        const tarjeta = elegido ? elegido.closest('[data-fondo-nombre]') : null;
        const monto = Number(String(campoMonto ? campoMonto.value : '').replace(',', '.')) || 0;

        nodosFondo.forEach((nodo) => {
            nodo.textContent = tarjeta ? tarjeta.dataset.fondoNombre : 'Sin elegir';
        });

        nodosMonto.forEach((nodo) => {
            const texto = formatearMonto(monto, moneda);

            if (nodo.textContent === texto) {
                return;
            }

            // El importe no salta de golpe: se atenua y vuelve. La clase la
            // resuelve el CSS; aqui no se decide ninguna duracion visual.
            nodo.classList.add('esta-cambiando');
            window.setTimeout(() => {
                nodo.textContent = texto;
                nodo.classList.remove('esta-cambiando');
            }, 120);
        });

        actualizarPasos(formulario, Boolean(tarjeta), monto);
    };

    formulario.querySelectorAll('input[name="fondo_id"]').forEach((radio) => {
        radio.addEventListener('change', refrescar);
    });

    if (campoMonto) {
        campoMonto.addEventListener('input', refrescar);
    }

    const acepta = formulario.elements.acepta_terminos;

    if (acepta) {
        acepta.addEventListener('change', refrescar);
    }

    refrescar();
}

/**
 * Indicador de pasos: 1 Proyecto → 2 Tus datos → 3 Pago.
 *
 * Es informativo y no cambia el flujo: solo dice donde estas. `aria-current`
 * se mueve con el paso activo para que un lector de pantalla lo anuncie; sin
 * eso seria decoracion que solo entiende quien ve la pantalla.
 */
function actualizarPasos(formulario, hayFondo, monto) {
    const pasos = document.querySelectorAll('[data-paso-indicador]');

    if (pasos.length === 0) {
        return;
    }

    const acepta = formulario.elements.acepta_terminos;
    const listoParaPagar = hayFondo && monto > 0 && Boolean(acepta && acepta.checked);

    const estados = {
        1: hayFondo ? 'hecho' : 'activo',
        2: hayFondo ? (listoParaPagar ? 'hecho' : 'activo') : 'pendiente',
        3: listoParaPagar ? 'activo' : 'pendiente',
    };

    pasos.forEach((paso) => {
        const estado = estados[paso.dataset.pasoIndicador] || 'pendiente';

        paso.dataset.estado = estado;

        if (estado === 'activo') {
            paso.setAttribute('aria-current', 'step');

            return;
        }

        paso.removeAttribute('aria-current');
    });
}

/** Paso 1 → paso 2. Con un solo fondo, el paso 1 ya viene resuelto. */
function configurarSeleccionDeFondo(formulario, paso2) {
    const continuar = formulario.querySelector('[data-donacion-continuar]');
    const radios = formulario.querySelectorAll('input[name="fondo_id"]');
    const avisoFondo = formulario.querySelector('[data-donacion-aviso-fondo]');

    if (!continuar || radios.length === 0) {
        return;
    }

    continuar.addEventListener('click', () => {
        const elegido = formulario.querySelector('input[name="fondo_id"]:checked');

        if (!elegido) {
            mostrarAviso(avisoFondo, 'Elige a qué fondo quieres aportar para continuar.', 'atencion');

            return;
        }

        mostrarAviso(avisoFondo, '', '');

        if (paso2) {
            paso2.hidden = false;

            // El foco sigue a la persona: si el paso aparece y el foco se
            // queda arriba, quien navega con teclado o lector no se entera.
            const primero = paso2.querySelector('input, select, textarea, button');
            if (primero) {
                primero.focus();
            }

            paso2.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
}

/** Botones de monto sugerido. Los valores los pone el servidor. */
function configurarMontosSugeridos(formulario, campoMonto) {
    const botones = formulario.querySelectorAll('[data-monto]');

    if (!campoMonto) {
        return;
    }

    botones.forEach((boton) => {
        boton.addEventListener('click', () => {
            campoMonto.value = boton.dataset.monto;
            botones.forEach((otro) => otro.setAttribute('aria-pressed', String(otro === boton)));
            campoMonto.dispatchEvent(new Event('input'));
        });
    });

    // Escribir un importe a mano deselecciona los botones: no puede parecer
    // que hay dos montos elegidos a la vez.
    campoMonto.addEventListener('input', () => {
        botones.forEach((boton) => {
            boton.setAttribute('aria-pressed', String(boton.dataset.monto === campoMonto.value));
        });
    });
}

function recogerDatos(formulario) {
    const valor = (nombre) => {
        const campo = formulario.elements[nombre];

        return campo ? String(campo.value ?? '').trim() : '';
    };

    const marcado = (nombre) => {
        const campo = formulario.elements[nombre];

        return campo ? Boolean(campo.checked) : false;
    };

    const fondo = formulario.querySelector('input[name="fondo_id"]:checked');

    return {
        fondo_id: fondo ? Number(fondo.value) : null,
        nombre: valor('nombre'),
        documento: valor('documento'),
        correo: valor('correo'),
        telefono: valor('telefono'),
        tipo_aportante: valor('tipo_aportante') || 'persona',
        monto: valor('monto'),
        moneda: formulario.dataset.moneda || 'PEN',

        // La casilla dice "donar de forma anónima": es lo contrario de
        // visible_publico.
        visible_publico: !marcado('anonimo'),
        acepta_terminos: marcado('acepta_terminos'),
    };
}

/**
 * Espejo de la validación del servidor. Los límites vienen del propio
 * formulario (`data-*`), que a su vez los recibió de config: así no hay dos
 * sitios donde cambiar un mínimo.
 */
function validarEnElNavegador(datos, formulario) {
    const minimo = Number(formulario.dataset.montoMinimo) || 0;
    const maximo = Number(formulario.dataset.montoMaximo) || Infinity;
    const monto = Number(String(datos.monto).replace(',', '.'));

    if (!datos.fondo_id) {
        return 'Elige a qué fondo quieres aportar.';
    }

    if (!Number.isFinite(monto) || monto <= 0) {
        return 'Escribe cuánto quieres donar.';
    }

    if (monto < minimo) {
        return `El monto mínimo es ${minimo}.`;
    }

    if (monto > maximo) {
        return `El monto máximo por donación es ${maximo}.`;
    }

    if (!datos.acepta_terminos) {
        return 'Debes aceptar los términos y la política de privacidad.';
    }

    return '';
}

/** Marca los campos que el servidor rechazó, para que se vean de un vistazo. */
function marcarCamposConError(formulario, respuesta) {
    const campos = respuesta.campos || {};

    Object.keys(campos).forEach((nombre) => {
        const contenedor = formulario.querySelector(`[data-campo="${nombre}"]`);

        if (!contenedor) {
            return;
        }

        contenedor.classList.add('don-campo--error');

        const hueco = contenedor.querySelector('[data-campo-error]');

        if (hueco) {
            hueco.innerHTML = escaparHtml(campos[nombre]);
        }

        const control = contenedor.querySelector('input, select, textarea');

        if (control) {
            control.setAttribute('aria-invalid', 'true');
        }
    });
}

function limpiarErrores(formulario, avisoGeneral) {
    mostrarAviso(avisoGeneral, '', '');

    formulario.querySelectorAll('.don-campo--error').forEach((contenedor) => {
        contenedor.classList.remove('don-campo--error');
        const hueco = contenedor.querySelector('[data-campo-error]');
        if (hueco) {
            hueco.textContent = '';
        }
    });

    formulario.querySelectorAll('[aria-invalid="true"]').forEach((control) => {
        control.removeAttribute('aria-invalid');
    });
}

function mostrarAviso(nodo, mensaje, tono) {
    if (!nodo) {
        return;
    }

    nodo.className = 'don-aviso' + (tono ? ` don-aviso--${tono}` : '');
    nodo.innerHTML = mensaje ? escaparHtml(mensaje) : '';
}

/**
 * Doble clic no puede crear dos donaciones.
 *
 * Ademas del `disabled`, el boton dice lo que esta pasando y ensena un disco
 * girando: sin ese aviso, el segundo que tarda Mercado Pago en responder
 * parece que el boton no funciono, y la reaccion natural es volver a pulsar.
 */
function bloquear(botones, bloqueado) {
    botones.forEach((boton) => {
        boton.disabled = bloqueado;

        if (!bloqueado) {
            boton.textContent = boton.dataset.textoOriginal || 'Donar con tarjeta';

            return;
        }

        // Marcado fijo, escrito aqui: no entra nada que venga de fuera.
        boton.innerHTML = '<span class="don-girador" aria-hidden="true"></span> Conectando con Mercado Pago…';
    });
}
