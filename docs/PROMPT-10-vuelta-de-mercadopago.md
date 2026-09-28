# PROMPT 10 — La vuelta de Mercado Pago, los diálogos y los estados

> Ejecuta este prompt entero. Termina en `### FIN DEL PROMPT ###`.
> Estado: fases 1–9 completas y desplegadas. 222 tests en verde.
> Todo lo que construyas usa los 48 tokens de `resources/css/tokens.css`.

---

## Reglas duras

1. **Ningún color, espaciado, radio, sombra o duración literal.** Todo por token.
2. **PHP 8.2.** Sin constantes de clase tipadas, `json_validate()` ni `#[\Override]`.
3. **El navegador NUNCA afirma un estado de pago.** El `?donacion=` de la URL es un mensaje provisional y cualquiera puede escribirlo a mano. El estado real lo establece el servidor consultando la API de Mercado Pago. Está documentado en `ResultadoDonacionController` y en `resultado.js`: **no lo toques, no lo "optimices", no lo cortocircuites**.
4. **No toques** `app/Services/MercadoPago/`, el controlador del webhook ni el middleware de firma.
5. Sin librerías nuevas. `<dialog>` nativo, CSS e `IntersectionObserver`.
6. Los 222 tests siguen pasando.
7. Al terminar: `php artisan test`, `./vendor/bin/pint --test`, `npm run build`, y commitea `public/build/` completo.

---

## 1. El momento de salir hacia Mercado Pago

Hoy, al pulsar «Donar con tarjeta», el botón se deshabilita y aparece un spinner. Luego el navegador salta a otro dominio sin más aviso. Quien no conoce Mercado Pago puede pensar que algo se rompió.

Diseña ese tránsito:

- **Una capa de transición a pantalla completa** (no un modal con botón de cerrar: no hay nada que decidir) que aparezca en cuanto el servidor devuelve el `init_point`. Con el logo de Comunitarios, un indicador de progreso y el texto: **«Te estamos llevando a Mercado Pago para completar tu aporte de S/ X»**.
- Debajo, en menor jerarquía: que el pago lo procesa Mercado Pago, que no guardamos su tarjeta, y que volverá aquí al terminar. **Que sepa que va a volver** es lo que quita el miedo.
- La capa se mantiene hasta que el navegador cambie de página. Si en 8 segundos no ha pasado nada, muestra un enlace directo al checkout y un texto que diga que puede continuar manualmente.
- Si la petición al servidor falla, la capa desaparece y el error se muestra en el formulario, con el mecanismo de errores que ya existe.
- `prefers-reduced-motion` la deja fija, sin animación de entrada.

---

## 2. La pantalla de resultado

`resources/views/publico/resultado.blade.php` + `resources/js/modules/resultado.js`. Es la pantalla que decide si el donante se va tranquilo o preocupado.

### La espera

Mientras `reconciliar` responde, la pantalla debe ser **honesta y legible**, no un esqueleto anónimo:

- Estado de espera con su propio texto: **«Estamos confirmando tu pago con Mercado Pago…»**, y debajo, que suele tardar unos segundos.
- Si hay reintentos (ya los hay), que el mensaje refleje que se sigue intentando, sin alarmar.
- Nada de mostrar «Aprobado» antes de tener la respuesta del servidor, ni siquiera un instante.

### Los cinco desenlaces

Cada uno con su tratamiento visual completo —orbe, color, titular, cuerpo, acciones— y con un texto que responda a lo que la persona se está preguntando en ese momento:

**Aprobado.** Celebración sobria: es una fundación, no un sorteo. Icono de confirmación con entrada elástica corta, sin confeti. Debe mostrar **el monto y el fondo al que fue**, qué pasa ahora (que ya suma al contador público), y que **el comprobante lo envía Mercado Pago a su correo** — nunca prometer un correo nuestro. Acciones: ver el proyecto, volver al inicio, y compartir la campaña.

**En proceso / pendiente.** El más delicado: la persona no sabe si pagó o no. Tiene que quedar clarísimo que **el dinero puede llegar igualmente** y que lo verá reflejado en el contador cuando se confirme, sin que tenga que hacer nada. Que no vuelva a pagar por si acaso. Dile cuánto suele tardar según el medio.

**Rechazado.** Sin culpar al donante. Explicar que el banco no autorizó el cargo y que **no se le cobró nada**. Qué puede hacer: intentar con otra tarjeta, o usar **Yape/Plin**, con enlace directo a ese canal. Y el correo de contacto.

**Sin respuesta tras los reintentos.** Honesto: no pudimos confirmarlo ahora mismo, pero la donación está registrada. Muéstrale **la referencia de su donación** para que pueda escribir citándola, y el correo de contacto. Esta pantalla es la que evita el mensaje de «me cobraron y no aparece nada».

**Llegó sin identificador.** Pasa cuando alguien abre `/donacion/resultado` a pelo, o vuelve desde Mercado Pago sin los parámetros. No es un error: ofrece ir a donar o al inicio, sin dar a entender que algo falló.

### Un detalle de Mercado Pago que hay que considerar

`auto_return` solo devuelve automáticamente al donante cuando el pago se aprueba. En los casos de rechazo o pendiente, Mercado Pago muestra su propia pantalla y **el donante tiene que pulsar «Volver al sitio»** — o puede no pulsarlo nunca. Diseña la pantalla de resultado asumiendo que puede llegar minutos después, o no llegar. Si detectas que se puede mejorar algo de la preferencia para suavizarlo, **propónlo en el informe, no lo cambies**: `ConstruirPreferencia` está fuera de tu alcance en este prompt.

---

## 3. Los diálogos del panel: los errores van dentro

**Problema real reportado.** Una administradora abrió el diálogo de verificación, pulsó «Rechazar» sin escribir el motivo, y el diálogo se cerró, la página recargó, y el mensaje *«Escribe por qué se rechaza»* apareció **arriba del todo**, fuera de su vista. Pensó que el sistema estaba roto y lo intentó varias veces.

Es el mismo fallo que ya se corrigió en el formulario público y que aquí quedó sin corregir.

Arréglalo para **todos** los diálogos del panel:

1. **Validación en el navegador antes de enviar.** Si falta el motivo, el campo se marca en rojo dentro del diálogo, con su mensaje, y el foco salta ahí. No se envía nada.
2. **Si aun así el servidor rechaza**, el diálogo **se vuelve a abrir** al recargar, con los valores que la persona había escrito y el error junto al campo que falla. No perder lo escrito.
3. El aviso de arriba puede seguir existiendo, pero **nunca es el único sitio** donde aparece el error.
4. Aplica a: verificar (aprobar/rechazar), revertir, eliminar usuario y eliminar fondo.

---

## 4. Después de revertir, decir qué sigue

Revertir devuelve la donación a `pendiente`, a la espera de una decisión nueva. Eso es correcto y no cambia. Lo que falta es explicarlo:

- Tras revertir, un aviso claro en esa fila o en el mensaje de éxito: **«Volvió a pendiente. Vuelve a decidir: aprobar o rechazar.»**
- Renombra el botón a **«Deshacer verificación»**. «Revertir» se confunde con «rechazar» y es justo la confusión que se ha producido.
- En el diálogo de reversión, una línea explícita: que **no descarta la donación**, solo deshace la decisión anterior.
- **Bug a corregir:** el texto guardado quedó como *«Revertida por tamoil el 28/09 16:08: aprobada por error+»*. Ese `+` final no debería estar. Encuentra de dónde sale y quítalo.

---

## 5. Anatomía única de diálogo

Hoy hay varios `<dialog>` —verificación, reversión, visor de comprobante, confirmaciones— y no comparten estructura. Unifícalos en un patrón, sin renombrar clases existentes sin motivo:

- **Cabecera**: título que dice qué va a pasar, sobre qué elemento concreto (nombre, monto).
- **Cuerpo**: la información necesaria para decidir. Si la acción es irreversible o mueve dinero, **el importe exacto y el efecto** en una línea destacada.
- **Pie**: acción principal a la derecha, cancelar a la izquierda, destructiva en coral.
- Escape cierra, el foco queda atrapado dentro, y al cerrar vuelve al elemento que lo abrió.
- Ancho máximo cómodo, radio `--radio-lg`, sombra `--sombra-elevada`, fondo del `::backdrop` oscurecido.
- **En móvil**: pegado abajo, ocupando el ancho, con las acciones al alcance del pulgar.
- Entrada de 250 ms; con `prefers-reduced-motion`, aparece sin animar.

El visor de comprobante mantiene su comportamiento propio (ampliar, arrastrar), pero comparte la barra superior y el cierre.

---

## 6. Entregable

1. `php artisan test` en verde.
2. `./vendor/bin/pint --test` limpio.
3. `npm run build` y `public/build/` commiteado.
4. Un commit con mensaje descriptivo.

Informe con:

- **La capa de tránsito**: qué muestra, qué pasa si tarda, qué pasa si falla.
- **Los cinco desenlaces**: el texto exacto de cada uno, tal como lo verá el donante.
- **Diálogos del panel**: cómo consigues que el error aparezca dentro y que no se pierda lo escrito.
- **El `+`**: de dónde salía.
- **Archivos tocados**, ruta y resumen de una línea.
- **Contraste** de todo color nuevo.
- **Responsive**: la pantalla de resultado y los diálogos en 360, 768 y 1440.
- **Movimiento**: cada animación, su duración, y que respeta `prefers-reduced-motion`.
- **Lo que NO hice y por qué**, incluida cualquier propuesta sobre la preferencia de Mercado Pago que hayas detectado pero no tocado.
- **Deuda pendiente.**

No resumas el informe.

### FIN DEL PROMPT ###
