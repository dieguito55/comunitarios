# PROMPT 08 — Panel de administración y enlaces de la portada

> Ejecuta este prompt **después** de `docs/PROMPT-05-canal-qr.md`.
> Si el canal QR todavía no está implementado, para y dilo: la sección 3 de
> este prompt depende de él.

---

## Contexto

Trabajas en el repositorio de comunitarios.org (Laravel 13 / PHP 8.2), ya en
producción. El sitio público de donaciones acaba de recibir un rediseño
completo sobre una escala de tokens (commit `d2596fa`): `resources/css/tokens.css`
pasó de 13 a 48 tokens —espaciado de 4 px, tipografía fluida con `clamp()`,
cinco radios, cuatro sombras en capas, foco único, tonos translúcidos,
duraciones y curva— más los cuatro tokens de contraste `--coral-texto`,
`--teal-texto`, `--yellow-texto` y `--borde-control`.

**El panel de administración se quedó fuera de ese rediseño.** Es el siguiente
encargo. Y hay dos enlaces mal apuntados en la portada.

---

## 0. Antes de empezar

1. Lee `resources/css/tokens.css` completo. Es la fuente única.
2. Lee `resources/css/admin.css` completo (583 líneas). Tiene una estructura de
   clases correcta (`admin-shell`, `admin-lateral`, `admin-nav`, `admin-tarjeta`,
   `admin-tabla`, `boton`, `campo`, `estado--*`, `fondo--*`). **No renombres
   ninguna.** El trabajo es de valores, no de nomenclatura.
3. Lee las vistas de `resources/views/admin/`.
4. Corre `php artisan test` y anota el resultado. Es tu línea base.

Reporta qué encontraste antes de tocar nada: qué valores están escritos a mano,
qué contrastes no llegan, qué falta.

---

## 1. Reglas duras

1. **Ningún color, espaciado, radio, sombra o duración literal.** Todo sale de
   `tokens.css`. `admin.css` ya lo importa.
2. **PHP 8.2.** Nada de constantes de clase tipadas, `json_validate()` ni `#[\Override]`.
3. **No toques la lógica de pago** ni los controladores de donación, webhook o
   firma. Este encargo es de interfaz.
4. **No cambies las rutas ni los nombres de ruta del panel.**
5. **No toques los contratos `data-*`** que lee el JavaScript. Si necesitas
   mover un elemento, actualiza el módulo y dilo en el informe.
6. **Todos los tests deben seguir pasando.** Si ajustas uno, explica por qué.
7. Al terminar: `npm run build` y commitea `public/build/` completo.

---

## 2. Rediseño del panel

El panel es donde se decide a dónde va el dinero de una fundación. Tiene que
verse **sobrio, denso y fiable** — no juguetón. El sitio público es cálido; el
panel es una herramienta de trabajo. Comparten tokens, no personalidad.

### 2.1 Estructura

- **Barra lateral fija** (`.admin-lateral`) de 260 px en escritorio, fondo
  `--navy`, con la marca arriba, la navegación en medio y el bloque de usuario
  abajo pegado al pie.
- **Debajo de 1024 px**: la lateral se convierte en barra superior con la
  navegación en horizontal y desplazamiento lateral si no cabe. No inventes un
  menú hamburguesa con JavaScript nuevo.
- **`.admin-main`** con padding generoso y ancho máximo cómodo para tablas:
  no lo encajones en `--container`, un listado de donaciones necesita aire.
- **`.admin-encabezado`**: título, descripción y acciones en una fila que se
  apila en móvil. El `<h1>` en `--texto-2xl`, la descripción en `--muted`.

### 2.2 Navegación

- Estado activo inequívoco: fondo translúcido claro, borde izquierdo de 3 px en
  `--yellow`, texto blanco. Hoy es casi indistinguible del resto.
- El activo lleva `aria-current="page"`.
- Hover con transición de `--transicion-rapida`.
- Iconos Lucide a la izquierda de cada entrada, alineados en rejilla fija para
  que los textos empiecen todos en la misma columna.

### 2.3 Tarjetas y cifras

- `.admin-tarjeta`: fondo `--white`, radio `--radio-lg`, borde `--borde-sutil`,
  `--sombra-sm`. Padding `--espacio-6`.
- `.admin-cifra`: número en `--texto-2xl` peso 700, etiqueta debajo en
  `--texto-sm --muted`, icono discreto arriba a la derecha. En rejilla, no en
  fila de texto.
- `.admin-rejilla`: columnas que colapsan limpiamente. Usa `minmax(0, 1fr)`,
  nunca `1fr` a secas — es lo que evita el desbordamiento con textos largos.

### 2.4 Tablas

Es la pieza que más se usa y la que peor está.

- Cabecera pegajosa (`position: sticky; top: 0`) dentro de
  `.admin-tabla-envoltorio`, para que al desplazar una lista larga no se pierdan
  los nombres de columna.
- Filas alternas con `--superficie-hundida` muy tenue. Hover con fondo
  `--blue-tenue`.
- Alineación por tipo: texto a la izquierda, **importes a la derecha con
  cifras tabulares** (`font-variant-numeric: tabular-nums`) — sin eso las
  columnas de dinero no se pueden comparar de un vistazo.
- Celdas con altura mínima cómoda (56 px) y padding `--espacio-3 --espacio-4`.
- En móvil, el envoltorio con desplazamiento horizontal y sombra en el borde
  que indique que hay más contenido a la derecha.

### 2.5 Insignias de estado

`.estado--activo`, `--borrador`, `--pausado`, `--cerrado`, `--aprobado`,
`--pendiente`, `--rechazado`:

- Píldora con `--radio-full`, texto en el token de contraste correspondiente
  (`--teal-texto`, `--yellow-texto`, `--coral-texto`) sobre su fondo tenue.
- Un punto de color de 6 px a la izquierda.
- **Verifica el contraste de cada combinación** y repórtalo. Ninguna baja de
  4.5:1.

### 2.6 Formularios

Misma gramática que el sitio público, en versión compacta:

- Campos de 44 px de alto, radio `--radio-md`, borde `--borde-control`.
- Anillo de foco `--foco-anillo`.
- Error: borde `--coral`, fondo `--coral-tenue`, mensaje en `--coral-texto`
  con icono. El error empuja, no tapa.
- Etiqueta arriba, ayuda debajo en `--texto-sm --muted`.
- Los formularios largos (crear/editar fondo) en dos columnas desde 768 px, con
  los campos de texto largo ocupando el ancho completo.
- **Agrupa en secciones con título**: identidad del fondo, contenido público,
  meta y fechas, medios. Hoy es una lista plana y es donde más se equivoca quien
  lo rellena.

### 2.7 Botones

- `.boton`: 40 px de alto, radio `--radio-md`, peso 600.
- `.boton--secundario`: fondo transparente, borde `--borde-control`.
- `.boton--peligro` y `.boton--confirmar`: coral sólido. **Toda acción
  destructiva pide confirmación** — si hoy no la pide, añádela con un `<dialog>`
  nativo, sin librerías y sin `confirm()` del navegador.
- Estados `:disabled` visibles: opacidad reducida y `cursor: not-allowed`.

### 2.8 Login

`.login-caja` es la primera pantalla que ve quien administra:

- Tarjeta centrada, ancho máximo 420 px, sobre fondo `--navy` a pantalla
  completa con el arco amarillo de la marca.
- Logo arriba, título, campos, botón de ancho completo.
- El error de credenciales, arriba de los campos, en su aviso.

### 2.9 Accesibilidad

- Contraste mínimo 4.5:1 en texto, 3:1 en bordes de control y texto grande.
  **Tabla completa en el informe.**
- Área táctil mínima 44×44 px en todo lo pulsable.
- `:focus-visible` en todo elemento interactivo.
- `.admin-saltar` sigue funcionando y es visible al recibir foco.
- Los avisos mantienen sus `role` y `aria-live`.
- El panel es una herramienta de trabajo: **el movimiento va al mínimo**.
  Transiciones de hover y foco, nada más. Sin entradas animadas, sin contadores.
  Respeta `prefers-reduced-motion` igualmente.

---

## 3. La cola de verificación del canal QR

Depende de `PROMPT-05`. Si ese canal ya está implementado, dale a su pantalla el
mismo tratamiento que al resto del panel, con dos cuidados propios:

- **El comprobante subido por el donante es el dato sensible de esta pantalla.**
  Se muestra en un visor con fondo oscuro, a tamaño suficiente para leer un
  número de operación, y con la acción de aprobar/rechazar siempre visible sin
  tener que desplazarse.
- Aprobar mueve dinero en los contadores públicos: esa acción pide confirmación
  y muestra el importe en el diálogo.

---

## 4. Enlaces de la portada

En `resources/views/welcome.blade.php` hay tres botones **«Súmate»** que hoy
abren WhatsApp:

- línea ~119, el CTA de la cabecera de escritorio
- línea ~123, el de la navegación móvil
- línea ~333, el de la sección de cierre («Juntos hacemos la diferencia»)

Los tres deben llevar a la página de donación del fondo destacado.

**No escribas el slug a mano.** Si mañana el fondo destacado cambia, un enlace a
`/donar/fundacion-antonia` se queda apuntando a un fondo cerrado. Resuélvelo
por el fondo marcado como predeterminado —el mismo concepto que ya usa
`PredeterminadoFondoController`— y cae a `route('donar')` si no hay ninguno
marcado o si el marcado no está abierto a donaciones.

El botón **«Conversemos»** de esa misma sección se queda en WhatsApp: es otra
intención.

Comprueba que no rompes ningún test de la portada y que el enlace sigue siendo
un `<a>` normal, sin JavaScript.

---

## 5. Entregable

1. `php artisan test` en verde.
2. `npm run build` ejecutado y `public/build/` commiteado.
3. Un commit con mensaje descriptivo.

Informe con esta estructura:

- **Qué encontré**: estado de `admin.css` y las vistas antes de tocar nada.
- **Valores migrados a token**: cuántos había escritos a mano y cuáles quedan.
- **Archivos tocados**: ruta y resumen de una línea.
- **Contraste**: tabla con cada par fondo/texto del panel y su ratio, incluidas
  las siete insignias de estado.
- **Responsive**: cómo queda el panel en 360, 768 y 1440, y en concreto qué pasa
  con la barra lateral y con las tablas.
- **Enlaces de la portada**: cómo resolviste el fondo destacado y qué pasa si no
  hay ninguno marcado.
- **Lo que NO hice y por qué.**
- **Deuda pendiente.**

No resumas el informe.

### FIN DEL PROMPT ###
