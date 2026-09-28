# PROMPT 09 — Revertir verificaciones, pendientes visibles, errores claros

> Ejecuta este prompt entero. Termina en `### FIN DEL PROMPT ###`.
> Estado: fases 1–8 completas y desplegadas. 185 tests en verde.
> Todo lo que construyas usa los 48 tokens de `resources/css/tokens.css`.

---

## Reglas duras

1. **Ningún color, espaciado, radio, sombra o duración literal.** Todo por token.
2. **PHP 8.2.** Sin constantes de clase tipadas, `json_validate()` ni `#[\Override]`.
3. **Una sola implementación del movimiento de contadores**: `MoverContadoresFondo`. Si necesitas mover dinero, pasa por ahí. No dupliques ni reimplementes.
4. **`monto_referencial` nunca se sobrescribe.** Es lo que declaró el donante.
5. **No toques** `app/Services/MercadoPago/`, el controlador del webhook ni el middleware de firma.
6. Los 185 tests siguen pasando. Si ajustas uno, explica por qué.
7. Al terminar: `php artisan test`, `./vendor/bin/pint --test`, `npm run build`, y commitea `public/build/` completo.

---

## 1. Revertir una verificación hecha por error

Hoy una aprobación del canal QR es definitiva: si alguien aprueba por equivocación, ese dinero se queda sumado al fondo para siempre y no hay forma de corregirlo desde el panel.

### Revertir, no borrar

**No implementes borrado de donaciones.** Una donación es un registro contable: si se borra, el dinero desaparece del historial sin rastro y la rendición de cuentas de la fundación deja de cuadrar. Lo que hace falta es **revertir**: devolver la donación a `pendiente`, o pasarla a `rechazado`, dejando constancia de quién lo hizo, cuándo y por qué.

Implementa:

- Acción **«Revertir verificación»** en la cola, disponible solo sobre donaciones del canal `qr_manual` que ya estén `aprobado` o `rechazado`.
- Pide **motivo obligatorio** (mínimo 10 caracteres). Queda guardado.
- Al revertir una aprobada, los contadores del fondo bajan **por `MoverContadoresFondo`**, que ya sabe restar. No escribas esa resta a mano.
- Registra quién revirtió y cuándo. Decide tú si reutilizas `verificado_por` / `verificado_at` limpiándolos, o añades columnas `revertido_por` / `revertido_at` / `motivo_reversion`. **Justifica la decisión en el informe**: lo que no puede pasar es que se pierda el rastro de la primera decisión.
- **Solo `superadmin`.** Quien verifica no debería poder deshacer su propia decisión sin supervisión. Ponlo en `DonacionPolicy`.
- Diálogo de confirmación `<dialog>` que muestre **el importe exacto que se va a restar del fondo** y el nombre del donante. Sin `confirm()` del navegador.

### El canal de Mercado Pago no se revierte a mano

Una donación de `canal_pago = mercadopago` **no lleva botón de revertir**. Su estado lo manda Mercado Pago: si hay una devolución o un contracargo, llega por webhook y el sistema ya lo procesa, bajando los contadores solo.

En la interfaz, donde iría el botón, pon una explicación corta: que ese estado lo controla Mercado Pago y que una devolución se hace desde el panel de Mercado Pago, no desde aquí. Que quede claro que no es una funcionalidad que falta, sino una decisión.

### Tests

- Revertir una aprobada devuelve el fondo a su recaudado anterior y baja el contador de aportes.
- Revertir una rechazada no mueve nada.
- Un `editor` no puede revertir (403).
- Una donación de Mercado Pago no se puede revertir por esta ruta, ni aunque se fuerce la petición.
- Revertir sin motivo, o con menos de 10 caracteres, se rechaza.

---

## 2. Los aportes pendientes tienen que verse

Hoy una donación por QR entra como `pendiente` y **no aparece en ningún contador** hasta que alguien la aprueba. La responsable del proyecto quiere ver ese dinero desde que la persona envía el formulario.

### Cómo hacerlo sin romper la rendición de cuentas

**Lo pendiente NO se suma a `recaudado`.** Publicar como recaudado un dinero que nadie ha verificado significa que el total baja cuando un comprobante resulta falso, y un contador que baja destruye la confianza en una fundación. `DASHBOARD_INCLUDE_PENDING` sigue en `false` y `fondos.recaudado` sigue contando solo lo confirmado.

Lo que sí haces: **exponer los pendientes como una cifra aparte, con su propia etiqueta**, en todas partes donde hoy solo se ve el recaudado.

- Calcula en vivo (`sum` sobre `monto_referencial` de las `pendiente` del canal manual), no denormalices: son pocas y cambian de estado rápido. Cachea 30 segundos como ya se hace con los donantes únicos.
- **En el panel**: en el resumen y en cada fondo, «S/ X por verificar (N aportes)», enlazando a la cola filtrada por ese fondo.
- **En la web pública** —tarjetas de fondo, página del fondo y dashboard—: la cifra confirmada manda visualmente, y debajo, en menor jerarquía, «+ S/ Y por verificar». Con un texto que explique qué significa: que son aportes recibidos por Yape o Plin que el equipo está confirmando.
- **La barra de progreso** puede mostrar el pendiente como un tramo distinto —más claro, o rayado— pero el porcentaje que se anuncia es **solo el confirmado**. Si lo haces, que se entienda sin leer la leyenda.
- Si no hay pendientes, la cifra no aparece. Nada de «S/ 0.00 por verificar».

### Qué le decimos al donante

Cuando alguien envía su comprobante, el mensaje debe dejar claro que su aporte **ya está registrado** y que aparecerá en el contador confirmado cuando el equipo lo revise. Hoy el texto ya va en esa dirección; revísalo y mejóralo si hace falta. Que nadie se quede pensando que su donación se perdió.

### Tests

- Una pendiente no mueve `fondos.recaudado`.
- La cifra de pendientes la incluye y desaparece cuando se aprueba o rechaza.
- Al aprobar, el importe pasa de «por verificar» a «recaudado» y no se cuenta dos veces.

---

## 3. Errores del formulario: que digan qué falta

**Problema real reportado:** una persona llenó todo el formulario, pulsó el botón y no pasó nada. Había olvidado marcar «Acepto los términos» y **el formulario no se lo dijo**. Se quedó atascada sin saber por qué.

Eso no puede volver a pasar con ningún campo.

### Reglas

1. **Nada se bloquea en silencio.** Si el envío no procede, hay un mensaje visible que dice exactamente qué falta y dónde.
2. **Si el botón se deshabilita**, tiene que haber un texto a su lado explicando qué falta para habilitarlo. Un botón gris sin explicación es peor que un botón que falla con mensaje.
3. **Al intentar enviar con errores**: se marcan todos los campos que fallan, **el foco salta al primero**, y la página se desplaza hasta él. En móvil esto es imprescindible: el error puede estar fuera de la pantalla.
4. **Resumen de errores arriba del formulario**, con `role="alert"`, listando lo que falta con enlaces a cada campo. Quien usa lector de pantalla necesita oírlo; quien ve la pantalla lo agradece igual.
5. **La casilla de términos** recibe el mismo tratamiento que cualquier campo: mensaje propio, visible, junto a ella. Algo como «Necesitas aceptar los términos para continuar».
6. **Mensajes concretos, en español, que digan qué hacer.** No «Campo inválido». Sí: «El correo no parece válido. Revisa que tenga @ y un dominio». No «Monto inválido». Sí: «El monto mínimo es S/ 5 y el máximo S/ 10,000».
7. **El servidor y el navegador dicen lo mismo.** El 422 ya devuelve `campos`; comprueba que **cada regla de validación** de `CrearDonacionRequest` y `CrearDonacionQrRequest` tiene su mensaje en español y que el JavaScript los pinta en el campo correcto. Auditalo regla por regla y repórtalo en una tabla.

### Ambos canales

Tarjeta **y** QR. El canal QR tiene además la subida del comprobante: si el archivo es demasiado grande, no es una imagen o no se envió, el mensaje lo dice con el límite concreto en MB.

### Diseño del estado de error

- El error empuja el contenido, no lo tapa ni lo solapa.
- Icono + texto en `--coral-texto`, borde del campo en `--coral`, fondo `--coral-tenue` muy tenue.
- El error desaparece cuando el campo se corrige, sin esperar a reenviar.
- `aria-invalid` y `aria-describedby` apuntando al mensaje.

---

## 4. Páginas de error con diseño

`resources/views/errors/` **no existe**: hoy un 404 o un 500 muestran la pantalla gris por defecto de Laravel, que no se parece en nada al sitio.

Crea, con el layout público y los tokens:

| Código | Cuándo pasa | Qué debe decir |
|---|---|---|
| **404** | URL que no existe, fondo borrado | Que no se encontró, con enlaces a inicio y a `/donar` |
| **419** | La sesión caducó mientras llenaba el formulario | **El más importante**: que su información sigue ahí, que recargue e intente de nuevo. Que no parezca que perdió el dinero |
| **429** | Demasiados intentos seguidos | Que espere un momento y vuelva a intentar |
| **500** | Fallo del servidor | Que el equipo ya está avisado, con el correo de contacto |
| **503** | Mantenimiento durante un despliegue | Que vuelve en unos minutos |

Cada una: icono, titular claro, una o dos frases sin jerga, y acciones útiles. **Nunca mostrar detalles técnicos** — `APP_DEBUG` está en `false` en producción y así se queda.

En las rutas `api/*` el manejador ya devuelve JSON; no lo cambies. Esto es solo para las páginas.

---

## 5. Seguir puliendo el diseño

Sobre el rediseño ya hecho, sin rehacerlo:

- **Los estados pendiente/aprobado/rechazado** necesitan un tratamiento visual consistente entre el panel y la web pública. Que un «pendiente» se vea igual de reconocible en los dos sitios.
- **Los diálogos de confirmación** (verificar, revertir, eliminar usuario) comparten una misma anatomía: qué va a pasar, sobre qué, y qué es irreversible. Unifícalos.
- **La cola de verificación** es la pantalla que más se va a usar: revisa que el flujo de mirar comprobante → decidir sea cómodo con una sola mano en móvil.
- **Portada**: los tres «Súmate» y el «Quiero donar» ya apuntan a `{{ $urlDonar }}` desde la fase 8. **Verifica que sigue así y no lo rompas.** Si encuentras algún otro enlace que debería llevar a donar y sigue en WhatsApp, cámbialo y dilo.
- Si algo del rediseño anterior quedó a medias o se ve peor de lo que esperabas, arréglalo y explícalo en el informe.

---

## 6. Entregable

1. `php artisan test` en verde.
2. `./vendor/bin/pint --test` limpio.
3. `npm run build` y `public/build/` commiteado.
4. Un commit con mensaje descriptivo.

Informe con:

- **Reversión**: dónde guardaste el rastro y por qué; qué pasa con los contadores; qué roles pueden.
- **Pendientes**: dónde aparece la cifra, cómo se calcula, y la confirmación explícita de que no se suma a `recaudado`.
- **Tabla de validación**: cada campo de los dos formularios × su regla × el mensaje que ve el usuario, en navegador y en servidor.
- **Páginas de error**: las cinco, con lo que dice cada una.
- **Archivos tocados**, ruta y resumen de una línea.
- **Contraste** de todo color nuevo.
- **Lo que NO hice y por qué.**
- **Deuda pendiente.**

No resumas el informe.

### FIN DEL PROMPT ###
