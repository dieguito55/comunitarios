/**
 * Punto de entrada del sitio publico de donaciones.
 *
 * Es un bundle aparte del de la portada (`app.js`), que no se toca: asi la
 * landing no carga codigo que solo usan las paginas de donacion, y estas no
 * cargan el catalogo entero de iconos de aquella.
 *
 * Cada modulo comprueba si su marcador existe en el DOM y, si no, no hace
 * nada. Ese es el contrato: una sola entrada para todas las paginas publicas.
 */

import { iniciarDashboard } from './modules/dashboard.js';
import { iniciarDonacion } from './modules/donacion.js';
import { pintarIconos } from './modules/iconos.js';
import { iniciarProgreso } from './modules/progreso.js';
import { iniciarQr } from './modules/qr.js';
import { iniciarResultado } from './modules/resultado.js';

document.addEventListener('DOMContentLoaded', () => {
    // Primero los iconos: el resto del arranque puede tardar y no queremos que
    // los huecos de los iconos se vean vacios mientras tanto.
    pintarIconos();

    iniciarDonacion();
    iniciarQr();
    iniciarResultado();
    iniciarDashboard();

    // Lo ultimo: las barras y los contadores ya tienen su valor pintado, asi
    // que esto solo les anade la entrada. Si falla, la pagina sigue correcta.
    iniciarProgreso();
});
