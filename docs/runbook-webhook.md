# Runbook — webhook de Mercado Pago

Cómo conectar el webhook de Mercado Pago a un entorno local y comprobar, de
principio a fin, que una donación pagada queda en `aprobado` con su
`monto_real` sin que nadie toque nada a mano.

> **Este procedimiento todavía NO se ha ejecutado.** Las credenciales de
> Mercado Pago no están en el `.env`. Quien las consiga es quien debe seguir
> estos pasos y confirmar, sobre todo, [la plantilla de la firma](#confirmar-la-plantilla-de-la-firma).

---

## 1. Puesta en marcha local

### 1.1 Levantar la aplicación

```bash
php artisan serve          # queda en http://127.0.0.1:8000
```

O servirla con Apache desde XAMPP, apuntando el *DocumentRoot* a `public/`.

### 1.2 Exponerla a internet con ngrok

Mercado Pago necesita llegar a nuestro servidor desde fuera, y por HTTPS.

```bash
ngrok http 8000            # o 'ngrok http 80' si sirves con Apache
```

ngrok devuelve una URL parecida a `https://a1b2-c3d4.ngrok-free.app`. **Esa URL
es la que usa todo lo demás.**

### 1.3 Rellenar el `.env`

```env
MP_ENV=sandbox
MP_ACCESS_TOKEN=TEST-...            # panel de MP → Tus integraciones → Credenciales de prueba
MP_PUBLIC_KEY=TEST-...
MP_WEBHOOK_URL=https://a1b2-c3d4.ngrok-free.app/api/webhooks/mercadopago

# Las tres DEBEN ser HTTPS. Con HTTP, la regla dura 3 hace que no se envíe
# auto_return y Mercado Pago rechaza la preferencia con un 400.
# El parametro se llama `donacion`, no `estado`: es el que lee
# ResultadoDonacionController para pintar el mensaje provisional.
MP_BACK_URL_SUCCESS=https://a1b2-c3d4.ngrok-free.app/donacion/resultado?donacion=exitosa
MP_BACK_URL_FAILURE=https://a1b2-c3d4.ngrok-free.app/donacion/resultado?donacion=fallida
MP_BACK_URL_PENDING=https://a1b2-c3d4.ngrok-free.app/donacion/resultado?donacion=pendiente

MP_SANDBOX_PAYER_EMAIL=test_user_XXXXX@testuser.com
```

Después de tocar el `.env`:

```bash
php artisan config:clear
```

### 1.4 Registrar el webhook en el panel de Mercado Pago

1. https://www.mercadopago.com.pe/developers → **Tus integraciones** → tu
   aplicación → **Webhooks**.
2. Modo **Pruebas**.
3. URL de producción: `https://a1b2-c3d4.ngrok-free.app/api/webhooks/mercadopago`
4. Eventos: **Pagos** (`payment`). Opcionalmente **Órdenes comerciales**
   (`merchant_order`); el endpoint maneja las dos.
5. **Guardar**. El panel muestra entonces la **clave secreta**. Cópiala:

```env
MP_WEBHOOK_SECRET=<la clave secreta que muestra el panel>
```

```bash
php artisan config:clear
```

> La clave secreta solo se muestra al crear o regenerar el webhook. Si se
> pierde, hay que regenerarla y actualizar el `.env`.

> **Sin `MP_WEBHOOK_SECRET`**, fuera de producción el middleware deja pasar la
> notificación con un aviso ruidoso en `storage/logs/webhooks-*.log`, para poder
> avanzar sin la clave. **Con `APP_ENV=production` rechaza todo con 401.**

---

## 2. Prueba de extremo a extremo

1. Abre el formulario de donación y crea una donación con Mercado Pago.
2. Paga en el checkout con una tarjeta de prueba y el titular **APRO**
   (aprueba el pago). Mercado Pago publica las tarjetas de prueba en
   *Tus integraciones → Cuentas de prueba*.
3. Vuelve al sitio. Comprueba:

```bash
# La notificación llegó y se procesó
php artisan tinker --execute="dump(App\Models\WebhookLog::latest('id')->first()->only(['evento','recurso_id','status','firma_valida','procesado']));"

# La donación quedó aprobada, con su dinero real
php artisan tinker --execute="dump(App\Models\Donacion::latest('id')->first()->only(['id','estado','monto_referencial','monto_real','mp_payment_id','mp_fee','mp_net_received','mp_live_mode']));"
```

Lo que debe verse:

| Campo | Valor esperado |
|---|---|
| `webhook_logs.firma_valida` | `true` ← si sale `false`, ve a [la sección de la firma](#confirmar-la-plantilla-de-la-firma) |
| `webhook_logs.status` | `actualizado` |
| `webhook_logs.procesado` | `true` |
| `donaciones.estado` | `aprobado` |
| `donaciones.monto_real` | el importe cobrado |
| `donaciones.monto_referencial` | **sin tocar**, el que declaró el donante |
| `donaciones.mp_live_mode` | `false` (es dinero de prueba) |

Los registros detallados están en:

```
storage/logs/webhooks-AAAA-MM-DD.log     lo que pasó con cada notificación
storage/logs/payments-AAAA-MM-DD.log     lo que cambió en cada donación
```

---

## 3. Confirmar la plantilla de la firma

**Esto es lo primero que hay que verificar con una notificación real, y el
motivo de que este documento exista.**

La validación usa el validador del propio SDK
(`MercadoPago\Webhook\WebhookSignatureValidator`), que construye el manifiesto
así:

```
id:<data.id>;request-id:<x-request-id>;ts:<ts>;
```

y calcula `HMAC-SHA256` con la clave secreta, comparando con `hash_equals()`.

El problema: **la documentación web de Mercado Pago dice que `data.id` va en
minúsculas, y el docblock del propio SDK lo afirma, pero su código NO lo hace**
(su `normalize()` solo recorta espacios). Ante esa contradicción,
`ValidarFirmaMercadoPago` prueba las dos variantes. Con ids de pago numéricos
da igual; con un recurso alfanumérico, no.

**Qué hacer con la primera notificación real:**

1. Mira `webhook_logs.firma_valida` de la última fila.
2. Si es `true`, la plantilla es correcta. Anótalo aquí y quita la doble
   variante de `ValidarFirmaMercadoPago::firmaCorrecta()` dejando solo la que
   funcionó.
3. Si es `false`, el detalle del motivo está en `webhooks-*.log`
   (`SIGNATURE_MISMATCH`, `MISSING_SIGNATURE_HEADER`, `TIMESTAMP_OUT_OF_TOLERANCE`…).
   La fila de `webhook_logs` guarda el `payload` completo con la `query_string`
   cruda, que es justo lo que hace falta para reconstruir el manifiesto a mano y
   comparar.

> Un 401 por firma **no toca ninguna donación**, así que equivocarse aquí no
> corrompe datos: solo hace que las notificaciones se rechacen. Pero también
> significa que los pagos no se acreditan solos, así que hay que resolverlo.

---

## 4. Simulador de notificaciones del panel

Panel de MP → **Webhooks** → **Simular notificación**.

- Elige el tipo de evento (`payment`), pega un `data.id` de un pago real de
  sandbox y envía.
- Sirve para probar el endpoint sin pagar otra vez, y para comprobar
  reintentos: el panel muestra el código HTTP que devolvimos.
- **Ojo:** algunas simulaciones del panel no firman igual que una notificación
  real. Si el simulador da 401 pero un pago real funciona, fíate del pago real.

Comprobaciones rápidas con el simulador:

| Qué probar | Cómo | Esperado |
|---|---|---|
| Duplicados | Enviar dos veces la misma simulación | Segunda fila con `status=duplicado`; el total no sube |
| Evento ajeno | Simular un topic distinto | `200` y `status=ignored` |
| Pago inexistente | `data.id` inventado | `500` (MP no lo encuentra) y MP reintentará |

---

## 5. Cuando la URL de ngrok cambia

Con el plan gratuito, **la URL cambia en cada reinicio de ngrok**. Cada vez:

1. Copia la URL nueva.
2. Actualiza en `.env`: `MP_WEBHOOK_URL` y las tres `MP_BACK_URL_*`.
3. `php artisan config:clear`
4. Panel de MP → Webhooks → editar la URL → **Guardar**.
5. **Comprueba si la clave secreta cambió.** Si el panel muestra una nueva,
   actualiza `MP_WEBHOOK_SECRET` y vuelve a `config:clear`. Si no lo haces,
   todas las notificaciones empezarán a dar 401.

Las preferencias creadas **antes** del cambio siguen apuntando a la URL vieja:
esas donaciones no recibirán webhook. Se recuperan solas por la red 2
(el navegador llama a `/api/donaciones/reconciliar` al volver del checkout) o,
si el donante cerró el navegador, por la red 3 en la fase 6.

Para evitar el baile de URLs, un dominio reservado de ngrok
(`ngrok http --domain=tu-dominio.ngrok-free.app 8000`) mantiene la misma URL
entre reinicios.

---

## 6. Diagnóstico rápido

| Síntoma | Causa probable | Qué mirar |
|---|---|---|
| No llega ninguna notificación | URL mal registrada, o ngrok caído | Consola de ngrok (muestra cada petición); panel de MP → Webhooks → entregas |
| `401` en todas | `MP_WEBHOOK_SECRET` vacío o desfasado | `webhooks-*.log`; regenera la clave en el panel |
| `401` solo a veces | Reloj desfasado: el `ts` se rechaza pasados 5 min | Sincroniza la hora del equipo |
| `500` y MP reintenta | Es lo correcto ante un error real | `webhook_logs.error_mensaje` dice qué falló |
| `200` con `status=huerfano` | El pago no corresponde a ninguna donación nuestra | Suele ser un pago de otra integración con el mismo token |
| `200` con `status=ignored` | Orden comercial sin pagos todavía (regla 4) | Normal: MP volverá a notificar |
| La donación no cambia y no hay error | Notificación más antigua que el estado guardado | `webhook_logs.status=obsoleto`; es la protección contra desorden |
| `status=conflicto` | Llegó un pago distinto para una donación ya vinculada | Lo tiene que revisar una persona: puede ser un doble pago |

---

## 7. Antes de pasar a producción

- [ ] `MP_ENV=production` y credenciales `APP_USR-` (no `TEST-`).
- [ ] `MP_WEBHOOK_SECRET` **obligatorio**: con `APP_ENV=production` y sin clave,
      el endpoint rechaza todo con 401 a propósito.
- [ ] Webhook registrado en modo **Producción**, apuntando al dominio real.
- [ ] Las tres `MP_BACK_URL_*` sobre el dominio real y con HTTPS.
- [ ] `php artisan migrate --force` ejecutado en el servidor: el workflow de
      despliegue **no** lo hace.
- [ ] Comprobar que `donaciones.mp_live_mode` es `true` en el primer pago real.
