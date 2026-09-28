# PROMPT 05 — Canal QR Yape / Plin

> Ejecuta este prompt entero. Termina en `### FIN DEL PROMPT ###`.
>
> **Estado actualizado a 28/09/2026:** las fases 1 a 4 están completas y
> desplegadas en producción (`comunitarios.org`). Además se ejecutó el rediseño
> UX/UI (commit `d2596fa`): `resources/css/tokens.css` tiene ahora 48 tokens
> (escalas de espaciado, tipografía fluida, radios, sombras, foco, duraciones y
> los cuatro tokens de contraste `--coral-texto`, `--teal-texto`,
> `--yellow-texto`, `--borde-control`). **Todo lo que construyas aquí usa esos
> tokens: ni un color, margen, radio o duración escrito a mano.**
>
> El CSS del sitio público ya tiene comentado el esqueleto de clases
> `.don-qr`, `.don-qr__imagen`, `.don-qr__pasos`, `.don-qr__comprobante`.
> Úsalo como punto de partida y termínalo.
>
> **La imagen del QR ya existe en el repositorio: `public/qr/qr.jpeg`.**
> `QR_YAPE_IMAGEN=/qr/qr.jpeg` ya está en el `.env` de producción. No pidas la
> imagen ni inventes una ruta distinta.
>
> Al terminar: `php artisan test` en verde, `npm run build`, y commitea
> `public/build/` completo — el hosting sirve el build desde el repositorio.

---

Eres un desarrollador senior Laravel. Estás implementando, por fases, el módulo
de donaciones de comunitarios.org, plataforma de crowdfunding de una fundación
territorial peruana.

Trabajas DENTRO del repositorio, editando y creando archivos reales.

═══════════════════════════════════════════════
ENTORNO
═══════════════════════════════════════════════

- Ruta: C:\xampp82\htdocs\comunitarios
- Laravel 13.25 · PHP 8.2.12 · MariaDB 10.4 (XAMPP) · Apache · Vite
- CÓDIGO PARA PHP 8.2. Nada de constantes de clase tipadas, json_validate()
  ni #[\Override]. `readonly class` sí es 8.2.
- Suite actual: 152 tests, 727 aserciones, todo verde. Pint limpio.

═══════════════════════════════════════════════
ESTADO: FASES 1 A 4 COMPLETAS
═══════════════════════════════════════════════

TABLAS  fondos · fondo_medios · donaciones · webhook_logs · admin_users ·
        vista v_fondos_conciliacion

  donaciones ya tiene, SIN USAR TODAVÍA: canal_pago, proveedor_pago,
  referencia_pago, comprobante_path, comprobante_mime, verificado_por,
  verificado_at. Esta fase les da uso.

ENUMS  CanalPago (MERCADOPAGO · QR_MANUAL · EFECTIVO_MANUAL) ·
       ProveedorPago (MERCADOPAGO · YAPE_QR · PLIN_QR · EFECTIVO · TRANSFERENCIA) ·
       EstadoDonacion · TipoAportante · RolAdmin · EstadoFondo · ColorFondo

SERVICIOS
  MercadoPago/  CrearPreferencia · ConstruirPreferencia · ResolverInitPoint ·
    MapearEstadoMp · NormalizarBackUrl · ConsultarPago · ConsultarOrden ·
    BuscarPagosPorReferencia
  Donaciones/ReconciliarDonacion.php   ← contiene moverContadoresDelFondo() PRIVADO
  Fondos/CalcularMetricasFondo.php · GuardarImagenFondo.php
  Publico/ConstruirDashboard.php

DISCOS  `fondos` → public/uploads/fondos  (público, imágenes de fondos)
        `comprobantes` → storage/app/private/comprobantes  (PRIVADO, sin usar aún)

PANEL  /admin con sesión, roles superadmin/editor, CRUD de fondos, galería,
       estados con confirmación escrita, usuarios, panel de salud.
       Policies: FondoPolicy · AdminUserPolicy

SITIO PÚBLICO  /donar · /donar/{slug} · /fondos/{slug} · /donacion/resultado
       GET /api/dashboard (cacheado 30 s, sin datos personales)
       En la página de donación hay un hueco reservado y visible para Yape/Plin
       en el bloque `.don-pagos`, con el texto "estará disponible muy pronto".

ARCHIVO YA EN EL REPO  public/qr/qr.jpeg  ← el QR estático de Yape/Plin de la
       organización. `QR_YAPE_IMAGEN=/qr/qr.jpeg` en .env.production.example.

PALETA (app.css — se puede AÑADIR al final, no modificar lo existente)
  --navy #052743 · --navy-deep #031d35 · --blue #064e9f · --coral #f3443f
  --yellow #f5ac18 · --teal #0c9d9b · --ink #092846 · --muted #52697d
  --line #dbe3e9 · --paper #f7f8f8 · Manrope + Caveat

═══════════════════════════════════════════════
DOCUMENTO DE REFERENCIA
═══════════════════════════════════════════════

GUIA_IMPLEMENTACION_DONACIONES.md. Para esta fase lee:
  | 1883–2059 | 8.1–8.2 Canal QR: flujo y qr_submit.php |
  | 2060–2125 | 8.3 Protección del directorio de comprobantes |
  | 3104–3276 | 12.4 Cola de verificación QR |
  | 3277–3301 | 12.5 Visor de comprobante con zoom |
  | 3468–3529 | 12.7 Confirmación antes de escribir dinero |

═══════════════════════════════════════════════
REGLAS DE TRABAJO
═══════════════════════════════════════════════

HAZ:
- Blade + JavaScript vanilla + CSS. Código y comentarios en español.
- declare(strict_types=1); tipado explícito; sintaxis PHP 8.2.

NO HAGAS:
- No instales NINGÚN paquete de composer ni de npm.
- No llames env() fuera de config/.
- No hagas commit ni push. No ejecutes migrate:fresh.
- No inventes textos institucionales ni cifras.
- Ningún color hex nuevo.


═══════════════════════════════════════════════
═══════════════════════════════════════════════
TAREA: FASE 5 — CANAL QR YAPE/PLIN CON COMPROBANTE
═══════════════════════════════════════════════
═══════════════════════════════════════════════

OBJETIVO
Que alguien pueda yapear directamente a la organización, subir la captura, y
que un administrador verifique cuánto entró de verdad y lo apruebe — con los
contadores moviéndose igual que en Mercado Pago.

POR QUÉ EXISTE ESTE CANAL: Mercado Pago Perú no expone una API de QR presencial.
Yape dentro de Checkout Pro ya funciona, pero cobra comisión y obliga a pasar
por la pasarela. Este canal es para quien prefiere transferir directo, y para
ferias y afiches donde solo hay un QR impreso.

AQUÍ ES DONDE `monto_referencial` vs `monto_real` SE GANA EL SUELDO: alguien
declara S/ 100 y transfiere S/ 80. El admin fija el monto real al verificar, y
los dos números conviven para siempre.


───────────────────────────────────────────────
1. PRIMERO: UNA SOLA IMPLEMENTACIÓN DE LOS CONTADORES
───────────────────────────────────────────────

`moverContadoresDelFondo()` es hoy un método PRIVADO de `ReconciliarDonacion`.
Esta fase necesita lo mismo desde la verificación manual. Copiarlo sería
garantizar que dentro de tres meses los dos difieran y las cifras dejen de
cuadrar sin que nadie sepa por qué.

Extráelo a `app/Services/Fondos/MoverContadoresFondo.php`, clase invocable:

  __invoke(Donacion $donacion, EstadoDonacion $anterior, EstadoDonacion $nuevo): float

Conserva TODO lo que ya hace: el `lockForUpdate()` sobre la fila del fondo,
sumar solo al entrar en `aprobado`, restar al salir, el registro de `error`
cuando el contador intentaría bajar de cero, y el `max(0, …)`.

`ReconciliarDonacion` pasa a inyectarlo y llamarlo. Sus tests deben seguir
verdes sin tocarlos: si alguno se rompe, la extracción cambió el comportamiento.


───────────────────────────────────────────────
2. ENVÍO PÚBLICO DEL COMPROBANTE
───────────────────────────────────────────────

POST /api/donaciones/qr    multipart/form-data, con throttle:donaciones

Campos: los mismos que el canal de tarjeta (fondo_id, nombre, documento,
correo, telefono, tipo_aportante, monto, anonimo, acepta_terminos) más
`comprobante` (el archivo) y `referencia_pago` opcional (el número de operación
que muestra Yape).

VALIDACIÓN DEL ARCHIVO — reutiliza `App\Rules\ImagenSegura` de la fase 3B y
extiéndela para admitir PDF:
  - mimes reales permitidos: image/jpeg, image/png, image/webp, application/pdf
  - MIME REAL con finfo, nunca la extensión ni lo que declare el navegador
  - máximo 10 MB (`config('donaciones.comprobante.max_mb')`)
  - SVG excluido a propósito, igual que en la fase 3B: puede llevar JavaScript

ALMACENAMIENTO:
  - disco PRIVADO `comprobantes`, nunca bajo public/ (deuda técnica 5)
  - ruta `YYYY/MM/<hash aleatorio>.<extensión del tipo real>`
  - el nombre lo ponemos nosotros, jamás el del usuario
  - se guarda `comprobante_path` y `comprobante_mime`

LA DONACIÓN NACE ASÍ:
  estado            = PENDIENTE
  canal_pago        = QR_MANUAL
  proveedor_pago    = YAPE_QR      (o PLIN_QR según lo que elija el donante)
  monto_referencial = lo que declaró
  monto_real        = null          ← lo fija el admin al verificar
  referencia_pago   = el número de operación, si lo puso

El mínimo es `config('donaciones.monto_minimo_qr')` (10), NO el de tarjeta.

Respuesta 201, mismo sobre que el resto:
  {"success":true,"donacion_id":123,"mensaje":"Recibimos tu comprobante..."}

NUNCA devuelvas `comprobante_path` al navegador: revelaría la estructura del
disco privado.


───────────────────────────────────────────────
3. PASO 2 DE LA PÁGINA DE DONACIÓN
───────────────────────────────────────────────

Sustituye el hueco reservado `.don-pagos__pendiente` por la segunda opción.

Dos botones: "Donar con tarjeta" (lo que ya hay) y "Donar con Yape/Plin".
Al elegir Yape/Plin se despliega, en la misma página:

  1. El QR estático, desde `config('donaciones.qr_imagen')`, a un tamaño que se
     pueda escanear desde otro móvil (mínimo 260 px de lado) y con `alt` real.
  2. El monto exacto a transferir, grande y con un botón de copiar.
  3. Selector Yape / Plin.
  4. Campo opcional para el número de operación.
  5. Zona de subida del comprobante, con vista previa de la imagen elegida
     ANTES de enviar. Para un PDF, el nombre y el tamaño.
  6. Un aviso honesto: "Tu aporte aparecerá en el contador cuando nuestro
     equipo verifique el comprobante. Suele tomar menos de 24 horas."

Al enviar, pantalla de éxito que NO dice que la donación esté confirmada: dice
que se recibió el comprobante y que será verificado.

Sin JavaScript el formulario tiene que seguir funcionando: renderízalo en el
servidor y que el JS solo añada el despliegue y la vista previa.


───────────────────────────────────────────────
4. COLA DE VERIFICACIÓN EN EL PANEL
───────────────────────────────────────────────

GET /admin/verificacion    (superadmin y editor: revisar comprobantes es
                            trabajo operativo, no una decisión de gobierno)

  - Lista de donaciones `qr_manual` en estado `pendiente`, las más antiguas
    primero: quien lleva más esperando, primero.
  - Por fila: fondo, nombre del donante (o "anónimo"), monto declarado,
    referencia, fecha, y miniatura del comprobante.
  - Filtro por estado y por fondo. Contador de cuántas esperan.

GET /admin/verificacion/{donacion}/comprobante
  Sirve el archivo DESDE EL DISCO PRIVADO, con `auth:admin` y una Policy.
  Nunca una URL directa al archivo (deuda técnica 5). Un test debe comprobar
  que sin sesión devuelve 403 o 404, nunca el archivo.

POST /admin/verificacion/{donacion}
  Aprobar o rechazar.

  AL APROBAR:
    - Pide el `monto_real`. Se precarga con el declarado, pero **es un campo
      que hay que confirmar conscientemente**, no un valor que se acepta solo.
    - Si el monto real difiere del declarado, exige confirmación explícita
      mostrando ambas cifras: "declaró S/ 100, vas a registrar S/ 80".
    - estado → APROBADO · monto_real → lo que se registró
      verificado_por → el admin · verificado_at → ahora
    - Mueve los contadores con `MoverContadoresFondo` (el mismo del punto 1)
    - Todo dentro de UNA transacción con `lockForUpdate()` sobre la donación:
      dos admins abriendo el mismo comprobante no pueden aprobarlo dos veces.

  AL RECHAZAR:
    - Pide un motivo (texto corto, obligatorio) y lo guarda
    - estado → RECHAZADO · monto_real se queda null
    - verificado_por y verificado_at igual
    - Los contadores NO se mueven

  SI YA FUE VERIFICADA: mensaje claro de que otra persona la revisó, con quién
  y cuándo. Nada de pisar silenciosamente el trabajo del otro.

VISOR DEL COMPROBANTE
  Ampliación al hacer clic, y que se pueda mover la imagen ampliada: las
  capturas de Yape se leen mal en pequeño y el monto es justo lo que hay que
  leer bien. Cerrable con Escape y con el foco atrapado dentro mientras está
  abierto. Para un PDF, enlace de descarga en lugar de visor.

Añade el contador de pendientes al resumen de /admin, junto al panel de salud.


───────────────────────────────────────────────
5. UNA COLUMNA NUEVA
───────────────────────────────────────────────

Migración que añade a `donaciones`:

  motivo_rechazo   string(300) nullable

Al $fillable del modelo. Solo se usa en verificación manual; en Mercado Pago el
motivo ya vive en `mp_status_detail`.


───────────────────────────────────────────────
6. EL DASHBOARD EN LA PORTADA
───────────────────────────────────────────────

La prohibición de tocar `welcome.blade.php` se levanta SOLO para esto: añade
`@include('publico.componentes.dashboard')` en el lugar que tenga sentido dentro
de esa página, y un enlace a `/donar` en el llamado a la acción principal si no
lo hay.

Nada más de ese archivo se toca. Di en el reporte exactamente qué líneas
añadiste y dónde.


───────────────────────────────────────────────
7. TESTS
───────────────────────────────────────────────

  AT-100  un .exe renombrado a .jpg → rechazado (MIME real)
  AT-101  archivo de 12 MB → rechazado
  AT-102  PDF válido → aceptado
  AT-103  SVG → rechazado aunque sea una imagen
  AT-104  la donación nace pendiente, qr_manual, monto_real null
  AT-105  el nombre original del archivo NO se conserva
  AT-106  el comprobante NO es accesible por URL directa desde public/
  AT-107  pedir el comprobante sin sesión de admin → 403 o 404
  AT-108  un editor SÍ puede verificar (es trabajo operativo)
  AT-109  aprobar con monto_real distinto: se guarda el real y
          monto_referencial NO se toca
  AT-110  al aprobar, el contador del fondo sube el monto REAL, no el declarado
  AT-111  al rechazar, el contador NO se mueve y se guarda el motivo
  AT-112  verificado_por y verificado_at se llenan en ambos casos
  AT-113  aprobar dos veces la misma donación: el contador sube UNA vez
  AT-114  monto por debajo de monto_minimo_qr → 422
  AT-115  fondo pausado → 422, igual que en el canal de tarjeta
  AT-116  la respuesta pública NO contiene comprobante_path
  AT-117  `MoverContadoresFondo` da el mismo resultado llamado desde
          ReconciliarDonacion y desde la verificación manual
  AT-118  los tests de ReconciliarDonacion siguen verdes tras la extracción
  AT-119  v_fondos_conciliacion.diferencia = 0 tras mezclar donaciones de los
          dos canales


───────────────────────────────────────────────
8. RECORRIDO MANUAL
───────────────────────────────────────────────

Con cookies y CSRF reales, como en la fase 3B:
  donar por QR subiendo una imagen → aparece en la cola → abrir el visor →
  aprobar con un monto distinto al declarado → comprobar que el contador del
  fondo subió el monto REAL → comprobar que el dashboard público lo refleja →
  rechazar otra y comprobar que no mueve nada

Limpia después lo que hayas creado.


───────────────────────────────────────────────
CRITERIOS DE ACEPTACIÓN
───────────────────────────────────────────────

[ ] php artisan test → los 152 anteriores MÁS los nuevos, todo verde
[ ] ./vendor/bin/pint --test → sin infracciones
[ ] npm run build sin errores
[ ] Ningún paquete nuevo
[ ] UNA sola implementación del movimiento de contadores
[ ] El comprobante no es alcanzable sin sesión de admin
[ ] monto_referencial nunca se sobrescribe
[ ] Ningún color hex nuevo
[ ] En welcome.blade.php solo se añadió el include y el enlace
[ ] Usable con teclado y a 360 px, visor incluido


═══════════════════════════════════════════════
REPORTE OBLIGATORIO
═══════════════════════════════════════════════

## REPORTE FASE 5

### 1. Archivos creados
### 2. Archivos modificados
Con el diff exacto de welcome.blade.php.
### 3. La extracción de los contadores
Qué movió, y cómo comprobaste que el comportamiento no cambió.
### 4. Protección del comprobante
Cómo garantizas que no se alcanza sin sesión, y qué test lo vigila.
### 5. Salida de los comandos
php artisan test · pint --test · npm run build
### 6. Recorrido manual
### 7. Criterios de aceptación
### 8. Decisiones que tomé por mi cuenta
### 9. Bloqueos y datos que faltan
### 10. Contenido íntegro de estos 4 archivos
- app/Services/Fondos/MoverContadoresFondo.php
- el controlador del envío público por QR
- el controlador de verificación del panel
- la vista de la cola de verificación

### FIN DEL PROMPT ###
