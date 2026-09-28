/**
 * Punto de entrada del sitio publico de donaciones.
 *
 * Es un bundle aparte del de la portada (`app.js`), que no se toca: asi la
 * landing no carga codigo que solo usan las paginas de donacion.
 *
 * Cada modulo comprueba si su marcador existe en el DOM y, si no, no hace
 * nada. Ese es el contrato: una sola entrada para todas las paginas publicas.
 */

import { iniciarDashboard } from './modules/dashboard.js';
import { iniciarDonacion } from './modules/donacion.js';
import { iniciarResultado } from './modules/resultado.js';

document.addEventListener('DOMContentLoaded', () => {
    iniciarDonacion();
    iniciarResultado();
    iniciarDashboard();
});
