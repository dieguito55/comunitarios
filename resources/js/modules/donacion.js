import { almacen, enviarJson, escaparHtml } from './api.js';

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
    const boton = formulario.querySelector('[data-donacion-enviar]');
    const avisoGeneral = formulario.querySelector('[data-donacion-aviso]');
    const campoMonto = formulario.querySelector('#donacion-monto');

    configurarSeleccionDeFondo(formulario, paso2);
    configurarMontosSugeridos(formulario, campoMonto);

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

        bloquear(boton, true);

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

        bloquear(boton, false);

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

/** Doble clic no puede crear dos donaciones. */
function bloquear(boton, bloqueado) {
    if (!boton) {
        return;
    }

    boton.disabled = bloqueado;
    boton.textContent = bloqueado ? 'Conectando con Mercado Pago…' : boton.dataset.textoOriginal;
}
