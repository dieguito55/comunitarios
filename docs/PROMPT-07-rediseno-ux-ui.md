# PROMPT 07 — Rediseño UX/UI del sitio público de donaciones

> Pégale esto completo a la IA que trabaja dentro del repositorio.
> Es un prompt de **diseño e interfaz**. No toca lógica de pagos.

---

## Contexto

Trabajas en `C:\xampp82\htdocs\comunitarios`, proyecto Laravel 13 / PHP 8.2.
El sitio ya está en producción en `https://comunitarios.org` y funciona:

- Portada: `resources/views/welcome.blade.php` (landing existente, ya tiene su identidad visual)
- Sitio público de donaciones: `resources/views/publico/` → `layout.blade.php`, `donar.blade.php`, `fondo.blade.php`, `resultado.blade.php`, `componentes/dashboard.blade.php`
- Rutas: `/donar`, `/donar/{fondo:slug}`, `/fondos/{fondo:slug}`, `/donacion/resultado`
- Estilos: `resources/css/tokens.css` (paleta, fuente única), `resources/css/app.css` (portada + bloque `SITIO PÚBLICO DE DONACIONES` a partir de la línea ~408), `resources/css/admin.css` (panel)
- JS público: `resources/js/publico.js` + `resources/js/modules/` (`donacion.js`, `dashboard.js`, `resultado.js`, `iconos.js`)
- Iconos: Lucide, vía `data-lucide="..."`

**Tu trabajo es elevar el diseño de las páginas públicas de donación al nivel de la portada y por encima.** El objetivo declarado por la dueña del proyecto: *minimalista, profesional, empresarial, con toque futurista; tarjetas y modales bien resueltos, curvas, espacios definidos, tamaños cómodos, animaciones elegantes*.

---

## 0. Antes de empezar — verificación obligatoria

1. Lee `resources/css/tokens.css` completo.
2. Lee el bloque `SITIO PÚBLICO DE DONACIONES` de `resources/css/app.css` completo. **Ya existe trabajo de diseño ahí**: no lo tires, evalúalo y constrúyelo encima. Si algo está a medio hacer, termínalo antes de inventar.
3. Lee las cuatro vistas de `resources/views/publico/`. Las clases ya son BEM (`don-panel`, `don-fondo__cuerpo`, `don-progreso__relleno`…). **Respeta los nombres existentes** salvo que expliques por qué cambias uno.
4. Ejecuta `php artisan test` y anota el resultado **antes** de tocar nada. Es tu línea base.

Reporta en tu informe final qué encontraste en el paso 2 y 3: qué estaba bien, qué estaba incompleto.

---

## 1. Reglas duras — no negociables

1. **Ningún color literal fuera de `tokens.css`.** Ni un `#hex`, ni un `rgb()` con valores de marca, en ningún `.css`, `.blade.php` o `.js`. Si necesitas un tono nuevo, lo declaras como token en `tokens.css` y lo usas por variable. Única excepción ya existente: el `<meta name="theme-color">` de `layout.blade.php`, que tiene un test que lo vigila — si cambias `--navy-deep`, actualiza también ese meta.
2. **PHP 8.2.** Nada de constantes de clase tipadas, `json_validate()` ni `#[\Override]`.
3. **No toques la lógica de pago.** `app/Services/MercadoPago/`, `app/Http/Controllers/.../CrearDonacionController.php`, `WebhookMercadoPagoController.php`, el middleware de firma y las migraciones quedan intactos. Si un cambio de diseño exigiera tocarlos, **para y explícalo en el informe** en vez de hacerlo.
4. **No inventes funcionalidad que no existe.** Concretamente: el canal QR de Yape/Plin **no está implementado** (solo existen los enums). El bloque que hoy dice *"El pago con Yape y Plin estará disponible muy pronto"* se queda como aviso — pero **bien diseñado**, no como una caja suelta. Ver sección 6.
5. **No prometas correos.** El sistema no envía ninguno. Cualquier texto nuevo debe decir que el comprobante lo emite Mercado Pago.
6. **Todos los tests deben seguir pasando.** Si uno falla por un cambio de marcado legítimo, ajusta el test y dilo en el informe. Si falla por otra cosa, arréglalo.
7. **Compila y commitea los assets.** Al terminar: `npm run build` y commitea `public/build/` completo. El hosting es compartido y sirve el build desde el repositorio — si no lo commiteas, producción se queda con el CSS viejo y el rediseño no se ve.

---

## 2. Sistema de diseño — amplía `tokens.css`

La paleta actual es correcta y es la de la marca. **No la cambies.** Amplíala con la escala que hoy falta y que es la causa de que el sitio se vea improvisado:

```css
/* Ya existen: --navy --navy-deep --blue --coral --yellow --teal
                --ink --muted --line --paper --white --shadow --container */
```

Añade, como tokens nuevos y bien comentados:

- **Escala de espaciado** de 4 px: `--espacio-1` … `--espacio-16`. Todo margin/padding/gap del sitio público sale de aquí. Nada de valores sueltos.
- **Escala tipográfica** fluida con `clamp()`: `--texto-xs` … `--texto-4xl`. El cuerpo no baja de 16 px en móvil. Los `<h1>` y `<h2>` respiran: `line-height` 1.1–1.2 en títulos, 1.6 en párrafos.
- **Radios**: `--radio-sm` (8 px), `--radio-md` (14 px), `--radio-lg` (20 px), `--radio-xl` (28 px), `--radio-full`. Las curvas generosas son parte del encargo.
- **Sombras en capas**: `--sombra-sm`, `--sombra-md`, `--sombra-lg`, `--sombra-elevada`. Sombras suaves y tintadas en navy, no negro puro. La `--shadow` actual se mantiene como alias para no romper la portada.
- **Bordes**: `--borde` (1 px `--line`), `--borde-fuerte`.
- **Transiciones**: `--transicion-rapida` (150 ms), `--transicion-media` (250 ms), `--transicion-lenta` (400 ms), y una curva `--curva: cubic-bezier(0.22, 1, 0.36, 1)` para ese aterrizaje suave.
- **Tonos derivados** que hoy se escriben a mano: superficies (`--superficie`, `--superficie-elevada`), estados de foco (`--foco`), y variantes translúcidas de coral/amarillo/teal para fondos suaves.

Si un token nuevo lo necesita también el panel, `admin.css` ya importa `tokens.css`: no dupliques.

---

## 3. Página `/donar` y `/donar/{slug}` — la pieza principal

Es el formulario por el que entra el dinero. Hoy es un apilado de bloques sin jerarquía. Rehazlo con esta estructura:

### 3.1 Layout

- **Dos columnas en escritorio** (≥1024 px): formulario a la izquierda (~62 %), resumen pegajoso a la derecha (~38 %). El `<aside class="don-aside">` con `position: sticky` y `top` por debajo de la cabecera.
- **Una columna en móvil**, con el resumen convertido en una **barra inferior fija** que muestre proyecto + monto + botón. Que el donante nunca tenga que buscar el botón.
- Ancho máximo del contenido y `gap` generoso. Nada pegado a los bordes: mínimo `--espacio-4` de aire lateral en móvil.

### 3.2 Los pasos

Los tres bloques (`data-paso="1"`, `="2"`, resumen) deben leerse como **pasos de verdad**:

- Un indicador de progreso arriba: 1 Proyecto → 2 Tus datos → 3 Pago. El paso activo marcado, los completados con check, los futuros apagados. Puramente visual, sin cambiar el flujo del JS.
- Cada `.don-panel` es una tarjeta: fondo `--white`, radio `--radio-lg`, borde sutil, sombra en capas, padding amplio.
- `.don-panel__numero` como círculo sólido con el número, no un número suelto.
- `.don-panel__titulo` con jerarquía real: tamaño, peso y espacio que lo separen del texto de ayuda.

### 3.3 Tarjetas de fondo (`.don-fondo`)

Hoy son la peor parte: la imagen, el nombre, el resumen y la cifra se atropellan.

- Tarjeta con imagen arriba en relación fija (16:10), `object-fit: cover`, esquinas superiores redondeadas.
- Cuerpo con jerarquía: nombre (`--texto-lg`, peso 700) → resumen (2 líneas máximo con `-webkit-line-clamp`, color `--muted`) → cifra recaudada destacada → barra de progreso → número de aportes en pequeño.
- Estado seleccionado inequívoco: borde de 2 px en el color del fondo, un check en la esquina, ligera elevación. El `:focus-visible` del input oculto debe pintar el anillo en la tarjeta.
- Hover: elevación + `translateY(-2px)` con `--transicion-media`.
- Rejilla responsive: 1 columna en móvil, 2 en tablet, 3 en escritorio. **Con un solo fondo, la tarjeta no debe quedarse diminuta a la izquierda** — que ocupe un ancho digno o se presente como tarjeta destacada horizontal.

### 3.4 Barra de progreso (`.don-progreso`)

La dueña la pidió explícitamente **más grande y más bonita**:

- Altura mínima 10 px, radio `--radio-full`, pista en `--line` y relleno con degradado entre dos tokens de marca.
- Animación de llenado al entrar en viewport (`IntersectionObserver`, en `dashboard.js` o módulo nuevo): de 0 % al valor real en ~900 ms con `--curva`.
- Brillo sutil recorriendo el relleno (`::after` con degradado y `@keyframes`), discreto — es una fundación, no un videojuego.
- El porcentaje y la meta legibles, no en 11 px grises.
- **Sin meta definida** (`CAMPANA_META` vacío o `fondo->meta` nulo): la barra se oculta y en su lugar va la cifra recaudada en grande. Ya es el comportamiento del backend; respétalo.

### 3.5 Formulario

- Campos con altura cómoda (mínimo 48 px), radio `--radio-md`, borde `--line`, fondo blanco.
- `:focus-visible` con anillo de 3 px en `--blue` translúcido + borde sólido. Visible de verdad.
- Etiqueta arriba, texto de ayuda debajo en `--muted --texto-sm`, error debajo en `--coral` con icono. El error **empuja** el contenido, no lo tapa.
- Estado de error en el campo: borde coral + fondo coral translúcido muy tenue.
- **Botones de monto** (`.don-monto`): tipo *chip*, grandes, radio `--radio-full`, estado seleccionado en navy sólido con texto blanco. Que se vean tocables: mínimo 44×44 px.
- Casillas (`.don-casilla`): checkbox personalizado de 22 px con check animado, no el nativo del navegador.
- Selects con flecha propia (`appearance: none` + icono), nunca el control del sistema.

### 3.6 Botón de pago

- Ancho completo, altura 56 px, radio `--radio-md`, degradado coral, sombra de color.
- Hover: sube 2 px, sombra más marcada. Activo: baja 1 px.
- **Estado cargando**: al enviar, el botón se deshabilita, el texto cambia a "Conectando con Mercado Pago…" y aparece un spinner. Hoy no hay feedback y el donante puede pulsar dos veces. Coordínalo con `donacion.js` **sin cambiar la petición ni el contrato del endpoint**.
- Debajo, en pequeño, los sellos de confianza: candado + "Pago seguro", los logos de medios aceptados, "No guardamos tu tarjeta".

### 3.7 Resumen lateral (`.don-resumen`)

- Tarjeta en navy oscuro, texto claro, radio `--radio-lg` — contraste con el formulario blanco. Es el bloque que la portada ya usa para el programa destacado: misma familia visual.
- Proyecto, monto en grande y actualizándose en vivo al elegir, y las tres garantías (pago seguro / destino trazable / cuentas públicas) como lista con iconos, no como párrafos pegados.
- El monto cambia con una transición numérica suave, no de golpe.

---

## 4. Página `/donacion/resultado`

Es lo primero que el donante ve después de pagar y hoy es un texto plano.

- Tres estados, cada uno con su tratamiento: **aprobado** (teal/verde, icono de check animado al entrar), **pendiente** (amarillo, icono de reloj), **rechazado o fallido** (coral, icono de alerta).
- Tarjeta central, centrada vertical y horizontalmente, ancho máximo ~560 px, con jerarquía: icono → titular → explicación → datos de la operación → acciones.
- Animación de entrada: la tarjeta sube 12 px con `fade` en 400 ms; el icono con un `scale` elástico corto. Nada de confeti.
- Mientras el navegador reconcilia el estado (lo hace `resultado.js`), un estado de carga honesto: esqueleto o spinner con "Confirmando tu pago…", no una pantalla en blanco.
- Acciones claras: volver al inicio, ver el proyecto, y —si falló— reintentar.
- **No prometas correo de la fundación.** El comprobante lo manda Mercado Pago.

---

## 5. Página `/fondos/{slug}` y dashboard público

- Cabecera del fondo con su imagen como fondo, degradado navy encima para que el texto se lea, título y datos clave.
- Cifras (`recaudado`, `aportes`, `personas`) como **tarjetas de estadística**: número grande, etiqueta pequeña en `--muted`, icono discreto. Alineadas en una rejilla, no en una fila de texto.
- Los números cuentan hacia arriba al entrar en viewport (~800 ms). Respeta `prefers-reduced-motion`.
- Lista de aportes recientes con jerarquía y estados vacíos bien resueltos: hoy el "Todavía no hay donaciones" es una caja gris sin gracia. Hazlo un estado vacío con ilustración simple (SVG inline, sin librerías), mensaje cálido y llamada a la acción.

---

## 6. El bloque de Yape / Plin

El canal QR **no está implementado**. No lo implementes aquí.

Lo que sí tienes que hacer: convertir esa línea suelta en un bloque con diseño — tarjeta con borde discontinuo suave, icono, título "Yape y Plin", texto breve explicando que estará disponible pronto, y aire alrededor. Que se lea como una funcionalidad anunciada, no como un error de maquetación.

Deja preparado en el CSS, comentado, el esqueleto de clases que usará el canal cuando se implemente (`.don-qr`, `.don-qr__imagen`, `.don-qr__pasos`, `.don-qr__comprobante`) **sin marcado que lo use todavía**.

---

## 7. Portada — integración

La portada ya tiene su identidad y **no se rediseña**. Solo estas mejoras puntuales sobre la tarjeta del programa destacado y su botón:

- **Botón "Quiero donar"**: que sea el elemento más llamativo de la tarjeta. Degradado coral, altura cómoda, radio generoso, sombra de color. Micro-animación en hover: elevación + el icono del corazón con un latido sutil. Un brillo que lo recorre cada ~4 s, muy discreto, para atraer la vista sin marear. Respeta `prefers-reduced-motion`.
- **Barra de progreso de la tarjeta**: misma barra rediseñada del punto 3.4, en versión sobre fondo oscuro.
- **Las cifras de esa tarjeta hoy están escritas a mano en el HTML.** Si ya las conectaste a la base de datos en el trabajo anterior, verifica que el formato y el caso "sin meta" se comporten igual que en `/donar`. Si no lo están, **no las inventes**: déjalas como estén y dilo en el informe.
- La tarjeta entera: al hacer hover, elevación muy leve. Al cargar la página, entrada con `fade` + subida de 16 px.

---

## 8. Movimiento — reglas

- Todo lo que se mueve usa `transform` y `opacity`. Nunca `width`, `height`, `top` ni `left` en animación.
- Duraciones: micro-interacciones 150–250 ms, entradas 400–600 ms, contadores y barras 800–1000 ms.
- Curva por defecto `--curva`. Nada de `linear` salvo en barras de carga indeterminadas.
- **`prefers-reduced-motion: reduce` desactiva todo**: sin entradas, sin contadores, sin brillos. El contenido aparece en su estado final. Esto es obligatorio, no opcional.
- Nada de animaciones en bucle infinito salvo el brillo del CTA principal y el spinner de carga.
- Sin librerías de animación. CSS y `IntersectionObserver`.

---

## 9. Accesibilidad — obligatorio

- Contraste mínimo **4.5:1** en texto normal, 3:1 en texto grande y en los bordes de los controles. Verifica cada combinación de tokens que uses y reporta los ratios en el informe.
- Área táctil mínima 44×44 px en todo lo pulsable.
- `:focus-visible` visible en **todo** elemento interactivo. No elimines outlines sin sustituirlos.
- El orden del DOM es el orden de lectura. Si usas `order` de flex o grid para reordenar, comprueba que no rompe la navegación por teclado.
- El indicador de pasos no es decorativo si comunica estado: usa `aria-current="step"`.
- Los estados de la barra de progreso mantienen `role="progressbar"` con `aria-valuenow`, `aria-valuemin`, `aria-valuemax` — si ya están en el marcado, no los quites.
- Los mensajes de error siguen en contenedores con `aria-live`. No cambies esos atributos.
- El `.skip-link` sigue funcionando y es visible al recibir foco.

---

## 10. Responsive

Puntos de corte: 480, 768, 1024, 1280.

- Móvil primero. En 360 px de ancho nada desborda horizontalmente.
- La tipografía fluida con `clamp()` evita saltos bruscos.
- La cabecera necesita menú móvil funcional: si la portada ya tiene uno, reutilízalo; si no lo tiene, el sitio de donaciones no es el lugar para inventarlo — dilo en el informe.
- Prueba mentalmente y describe en el informe cómo queda cada página en 360, 768 y 1440.

---

## 11. Qué NO hacer

- No añadas Tailwind a las vistas públicas si hoy no lo usan. El sitio público es CSS propio con tokens; mantener dos sistemas es peor que uno imperfecto.
- No instales librerías de UI, de animación ni de iconos nuevas. Lucide ya está.
- No cambies el HTML que el JavaScript busca por `data-*`. Si necesitas mover un elemento, actualiza el módulo correspondiente y dilo.
- No toques `welcome.blade.php` más allá de lo que pide la sección 7.
- No cambies textos legales, de privacidad, ni los avisos sobre quién emite el comprobante.
- No borres los comentarios explicativos del CSS y las vistas. Amplíalos.

---

## 12. Entregable

Al terminar:

1. `php artisan test` en verde. Si ajustaste algún test, dilo y explica por qué.
2. `npm run build` ejecutado y `public/build/` commiteado.
3. Un commit con mensaje descriptivo.

Y un **informe** con esta estructura:

- **Qué encontré**: estado del CSS y las vistas antes de tocar nada (paso 0).
- **Tokens añadidos**: lista con nombre, valor y para qué sirve.
- **Archivos tocados**: ruta y resumen de una línea de cada cambio.
- **Contraste**: tabla con cada par fondo/texto usado y su ratio.
- **Responsive**: cómo queda cada página en 360, 768 y 1440.
- **Movimiento**: lista de animaciones añadidas, duración y si respetan `prefers-reduced-motion`.
- **Lo que NO hice y por qué**: todo lo que este prompt pedía y decidiste no hacer, con la razón.
- **Deuda pendiente**: lo que quedó a medias o requiere una decisión de la dueña.

No resumas el informe. Es lo que se revisa antes de desplegar.
