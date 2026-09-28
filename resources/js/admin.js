/**
 * JavaScript del panel de administración.
 *
 * Hasta ahora el panel no tenía ninguno: todo se resolvía con formularios y
 * recargas, que es lo correcto para un CRUD. La cola de verificación necesita
 * dos cosas que no se pueden hacer sin él —ampliar una captura y avisar de una
 * diferencia de importe antes de enviar— y nada más.
 *
 * REGLA DE ESTE ARCHIVO: **todo lo que hace es opcional**. Sin JavaScript, la
 * cola sigue siendo una tabla con enlaces al comprobante y formularios que el
 * servidor valida igual. Si algo de aquí falla, no se pierde ninguna función,
 * solo comodidad.
 *
 * Sin librerías. `<dialog>` nativo, que ya trae el foco atrapado, el cierre con
 * Escape y el fondo inerte sin que haya que programarlos.
 */

import {
    createIcons,
    FolderHeart,
    LayoutDashboard,
    ReceiptText,
    TriangleAlert,
    Undo2,
    UsersRound,
} from 'lucide';

/**
 * Solo los iconos que se usan, uno a uno.
 *
 * `import { icons }` arrastraria el catalogo entero —casi 400 kB— para pintar
 * cinco dibujos en un menu.
 */
const ICONOS = { FolderHeart, LayoutDashboard, ReceiptText, TriangleAlert, Undo2, UsersRound };

function pintarIconos() {
    try {
        createIcons({ icons: ICONOS, attrs: { 'stroke-width': 1.8 } });
    } catch (error) {
        // Un icono que no se dibuja no puede romper el panel: al lado hay
        // texto que dice lo mismo.
    }
}

document.addEventListener('DOMContentLoaded', () => {
    pintarIconos();
    iniciarDialogosDeDecision();
    iniciarDialogosSimples('[data-revertir-abrir]', 'revertirId', 'data-revertir', '[data-revertir-cerrar]');
    iniciarConfirmaciones();
    iniciarVisor();
});

/**
 * Abrir y cerrar un <dialog> por pares boton/dialogo.
 *
 * Los de reversion no llevan la vigilancia del monto que si necesitan los de
 * decision, asi que comparten solo esta parte.
 */
function iniciarDialogosSimples(selectorBoton, clave, atributo, selectorCerrar) {
    document.querySelectorAll(selectorBoton).forEach((boton) => {
        const dialogo = document.querySelector(`[${atributo}="${CSS.escape(boton.dataset[clave])}"]`);

        if (!dialogo || typeof dialogo.showModal !== 'function') {
            return;
        }

        boton.addEventListener('click', () => dialogo.showModal());

        dialogo.querySelectorAll(selectorCerrar).forEach((cerrar) => {
            cerrar.addEventListener('click', () => dialogo.close());
        });
    });
}

/* ── Confirmación de acciones destructivas ───────────────────────────────── */

/**
 * Sustituye al `confirm()` del navegador.
 *
 * Aquella caja no se puede estilar, cambia de forma en cada sistema y varios
 * navegadores dejan silenciarla —una casilla de «no volver a mostrar» que el
 * usuario marca sin querer y que deja de proteger nada. Un `<dialog>` nativo
 * da lo mismo, con el aspecto del panel y sin depender de eso.
 *
 * Si el navegador no soportara `<dialog>`, el formulario se envía tal cual:
 * nunca se bloquea una acción por no poder preguntar.
 */
function iniciarConfirmaciones() {
    const formularios = document.querySelectorAll('form[data-confirmar]');

    if (formularios.length === 0) {
        return;
    }

    const dialogo = document.querySelector('[data-confirmacion]');

    if (!dialogo || typeof dialogo.showModal !== 'function') {
        return;
    }

    const texto = dialogo.querySelector('[data-confirmacion-texto]');
    const aceptar = dialogo.querySelector('[data-confirmacion-aceptar]');
    let pendiente = null;

    formularios.forEach((formulario) => {
        formulario.addEventListener('submit', (evento) => {
            if (formulario.dataset.confirmado === 'si') {
                return;
            }

            evento.preventDefault();
            pendiente = formulario;

            if (texto) {
                texto.textContent = formulario.dataset.confirmar;
            }

            dialogo.showModal();
        });
    });

    aceptar?.addEventListener('click', () => {
        dialogo.close();

        if (!pendiente) {
            return;
        }

        // Se marca y se reenvía: el segundo submit ya pasa de largo.
        pendiente.dataset.confirmado = 'si';
        pendiente.requestSubmit();
        pendiente = null;
    });

    dialogo.querySelectorAll('[data-confirmacion-cancelar]').forEach((cancelar) => {
        cancelar.addEventListener('click', () => {
            pendiente = null;
            dialogo.close();
        });
    });
}

/* ── Diálogos de aprobar / rechazar ──────────────────────────────────────── */

function iniciarDialogosDeDecision() {
    document.querySelectorAll('[data-decidir-abrir]').forEach((boton) => {
        const dialogo = document.querySelector(`[data-decidir="${CSS.escape(boton.dataset.decidirId)}"]`);

        if (!dialogo || typeof dialogo.showModal !== 'function') {
            return;
        }

        boton.addEventListener('click', () => dialogo.showModal());

        dialogo.querySelectorAll('[data-decidir-cerrar]').forEach((cerrar) => {
            cerrar.addEventListener('click', () => dialogo.close());
        });

        vigilarDiferenciaDeMonto(dialogo);
    });
}

/**
 * Avisa en cuanto el monto escrito deja de coincidir con el declarado.
 *
 * El servidor exige la confirmación igualmente —está en
 * VerificarDonacionRequest—, así que esto no es la barrera: es enseñar la
 * barrera antes de chocar con ella. Registrar S/ 80 donde alguien declaró
 * S/ 100 es una decisión contable, y verla escrita mientras se teclea evita
 * tanto el error de dedo como el envío sin mirar.
 */
function vigilarDiferenciaDeMonto(dialogo) {
    const campo = dialogo.querySelector('[data-monto-declarado]');
    const aviso = dialogo.querySelector('[data-aviso-diferencia]');

    if (!campo || !aviso) {
        return;
    }

    const casilla = aviso.querySelector('input[type="checkbox"]');
    const hueco = aviso.querySelector('[data-diferencia-real]');
    const declarado = Number(campo.dataset.montoDeclarado);

    const revisar = () => {
        const escrito = Number(campo.value);
        const diferente = Number.isFinite(escrito) && escrito > 0
            && Math.abs(escrito - declarado) >= 0.005;

        aviso.hidden = !diferente;

        if (!diferente && casilla) {
            casilla.checked = false;
        }

        if (diferente && hueco) {
            hueco.textContent = escrito.toFixed(2);
        }
    };

    campo.addEventListener('input', revisar);
    revisar();
}

/* ── Visor del comprobante ───────────────────────────────────────────────── */

/**
 * Ampliar y arrastrar la captura.
 *
 * Las capturas de Yape llegan con el monto en una tipografía pequeña y a veces
 * recortadas: sin poder ampliar y moverse por la imagen, verificar es adivinar.
 */
function iniciarVisor() {
    const visor = document.querySelector('[data-visor]');

    if (!visor || typeof visor.showModal !== 'function') {
        return;
    }

    const imagen = visor.querySelector('[data-visor-imagen]');
    const lienzo = visor.querySelector('[data-visor-lienzo]');
    const titulo = visor.querySelector('[data-visor-titulo]');

    document.querySelectorAll('[data-visor-abrir]').forEach((boton) => {
        boton.addEventListener('click', () => {
            imagen.src = boton.dataset.visorSrc;
            imagen.alt = boton.dataset.visorTitulo || 'Comprobante';

            if (titulo) {
                titulo.textContent = boton.dataset.visorTitulo || 'Comprobante';
            }

            lienzo.classList.remove('esta-ampliado');
            lienzo.scrollTo(0, 0);
            visor.showModal();
        });
    });

    visor.querySelectorAll('[data-visor-cerrar]').forEach((cerrar) => {
        cerrar.addEventListener('click', () => visor.close());
    });

    // Clic en la imagen: alterna el zoom.
    imagen.addEventListener('click', () => lienzo.classList.toggle('esta-ampliado'));

    arrastrarPara(lienzo);

    // Al cerrar se suelta la imagen: es una captura bancaria y no tiene por qué
    // seguir en memoria con el diálogo cerrado.
    visor.addEventListener('close', () => {
        imagen.removeAttribute('src');
    });
}

/** Arrastre con el puntero sobre un contenedor con desplazamiento. */
function arrastrarPara(lienzo) {
    let arrastrando = false;
    let inicioX = 0;
    let inicioY = 0;
    let desdeIzquierda = 0;
    let desdeArriba = 0;

    lienzo.addEventListener('pointerdown', (evento) => {
        if (!lienzo.classList.contains('esta-ampliado')) {
            return;
        }

        arrastrando = true;
        inicioX = evento.clientX;
        inicioY = evento.clientY;
        desdeIzquierda = lienzo.scrollLeft;
        desdeArriba = lienzo.scrollTop;
        lienzo.setPointerCapture(evento.pointerId);
    });

    lienzo.addEventListener('pointermove', (evento) => {
        if (!arrastrando) {
            return;
        }

        lienzo.scrollLeft = desdeIzquierda - (evento.clientX - inicioX);
        lienzo.scrollTop = desdeArriba - (evento.clientY - inicioY);
    });

    const soltar = (evento) => {
        if (!arrastrando) {
            return;
        }

        arrastrando = false;

        if (lienzo.hasPointerCapture(evento.pointerId)) {
            lienzo.releasePointerCapture(evento.pointerId);
        }
    };

    lienzo.addEventListener('pointerup', soltar);
    lienzo.addEventListener('pointercancel', soltar);
}
