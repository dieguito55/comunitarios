# Poner comunitarios.org en producción (cPanel)

Guía paso a paso. Está escrita para que la siga **alguien que no programa**:
cada paso dice qué hacer, qué deberías ver y qué hacer si sale mal.

Reserva **una hora y media** la primera vez. No hace falta saber programar, pero
sí ir en orden: varios pasos dependen del anterior.

## Antes de empezar, ten a mano

- Usuario y contraseña de **cPanel**.
- Del repositorio: `database/sql/comunitarios-schema.sql` y
  `.env.production.example`.
- Los dos comprimidos que te pasa quien programa: **`vendor.zip`** y
  **`build.zip`**.
- Las **credenciales de producción de Mercado Pago** (las que empiezan por
  `APP_USR-`).

> **¿Por qué hay que subir dos zips?**
> El servidor de Namecheap no trae Composer ni Node, que son las herramientas
> que descargan las librerías del proyecto y compilan los estilos. Así que esas
> dos carpetas se generan en el ordenador de quien programa y se suben ya
> hechas. No es un apaño: es lo normal en hosting compartido.

En toda la guía verás **`USUARIO`**. Sustitúyelo siempre por el nombre de tu
cuenta de cPanel: lo ves arriba a la derecha del panel, o escribiendo `whoami`
en cPanel → Terminal.

---

## 1. Elegir la versión de PHP y activar las extensiones

cPanel → **Select PHP Version**.

1. Elige **PHP 8.3** (o **8.2**; las dos valen, 8.3 va algo más rápido).
2. En la pestaña **Extensions**, asegúrate de que estas están marcadas:

| Extensión | Para qué |
|---|---|
| `pdo_mysql` | Hablar con la base de datos |
| `mbstring` | Nombres con tildes y ñ |
| `openssl` | HTTPS y el cifrado de las sesiones |
| `curl` | Llamar a la API de Mercado Pago |
| `fileinfo` | **Obligatoria.** El panel comprueba el tipo real de cada imagen leyendo su contenido. Sin esto, subir una portada falla siempre |
| `json`, `tokenizer`, `xml`, `dom`, `ctype`, `filter`, `hash`, `session` | Las exige Laravel |
| `zip` | Descomprimir, si lo haces por consola |

3. Pulsa **Save**.

**Comprobar:** arriba debe poner `PHP 8.3` (o `8.2`) como *Current PHP version*.

> Anota la ruta del PHP de esa versión, la vas a necesitar. En cPanel →
> **Terminal**, escribe `which php`. Normalmente es
> `/opt/cpanel/ea-php83/root/usr/bin/php`.

---

## 2. Crear la base de datos

cPanel → **MySQL® Database Wizard**. Son tres pantallas.

1. **Nombre de la base**: escribe `comunitarios`.
   cPanel le pone delante el prefijo de tu cuenta, así que quedará
   `USUARIO_comunitarios`. **Anota el nombre completo.**
2. **Usuario y contraseña**: crea un usuario, por ejemplo `comuweb`, y pulsa el
   botón **Password Generator**. **Copia la contraseña a un sitio seguro ahora**:
   después no se puede volver a ver.
3. **Privilegios**: marca **ALL PRIVILEGES** y pulsa *Make Changes*.

Al terminar tienes que tener anotados **tres valores**:

```
DB_DATABASE = USUARIO_comunitarios
DB_USERNAME = USUARIO_comuweb
DB_PASSWORD = (la que generaste)
```

---

## 3. Importar el esquema de la base

cPanel → **phpMyAdmin**.

1. En la columna izquierda, pulsa sobre **`USUARIO_comunitarios`**.
   Verás *No tables found in database* — correcto, está vacía.
2. Pestaña **Importar** (*Import*).
3. **Seleccionar archivo** → elige `database/sql/comunitarios-schema.sql`.
4. Deja el juego de caracteres en **utf-8** y pulsa **Continuar**.

**Qué deberías ver:** una barra verde,
*«La importación se ejecutó exitosamente»*, y en la izquierda aparecen
**14 tablas más una vista** (`v_fondos_conciliacion`, con otro icono).

**Comprobar que entró bien.** Pestaña **SQL**, pega esto y ejecuta:

```sql
SELECT COUNT(*) AS migraciones FROM migrations;
SELECT slug, estado FROM fondos;
```

Debe responder `14` y una fila, `fundacion-antonia | activo`.

**Si falla:**

| Mensaje | Qué pasa |
|---|---|
| *#1227 access denied; you need SUPER privileges* | El `.sql` que te pasaron lleva un `DEFINER`. Pide el archivo regenerado: el bueno no lo lleva |
| *#1050 Table already exists* | La base no estaba vacía. Bórrala y créala otra vez (paso 2) |
| *El archivo es demasiado grande* | Comprime el `.sql` en `.zip` y sube el zip: phpMyAdmin lo acepta |

---

## 4. Traer el código con Git Version Control

cPanel → **Git™ Version Control** → **Create**.

1. Activa **Clone a Repository**.
2. **Clone URL**: la del repositorio.
3. **Repository Path**: déjalo como cPanel lo propone,
   `/home/USUARIO/repositories/comunitarios`. Es su carpeta por defecto para
   repositorios y está **fuera de `public_html`**, que es justo lo que hace
   falta.

> ⚠️ **Nunca lo clones dentro de `public_html`.** Si el repositorio queda ahí,
> cualquiera puede descargarse el archivo de contraseñas escribiendo la URL.
> En el paso 9 apuntamos el dominio a la subcarpeta `public/` del repositorio,
> que es la única que debe ser accesible.

4. **Create**.

**Comprobar:** en **File Manager** existe `/home/USUARIO/repositories/comunitarios` y dentro
hay carpetas como `app`, `config`, `public`, `routes`.

---

## 5. Subir y extraer `vendor.zip` y `build.zip`

cPanel → **File Manager**, entra en `/home/USUARIO/repositories/comunitarios`.

1. Botón **Upload** → sube **`vendor.zip`**.
2. Vuelve a la carpeta, clic derecho sobre `vendor.zip` → **Extract**.
   La ruta de destino tiene que ser `/home/USUARIO/repositories/comunitarios`.
3. Repite con **`build.zip`**, que se extrae dentro de `public/`.
   Destino: `/home/USUARIO/repositories/comunitarios/public`.
4. **Borra los dos `.zip`** cuando termines.

**Comprobar** que existen exactamente estos dos archivos:

```
/home/USUARIO/repositories/comunitarios/vendor/autoload.php
/home/USUARIO/repositories/comunitarios/public/build/manifest.json
```

Si alguno no está, la extracción se hizo en el sitio equivocado: lo más típico
es que quede `vendor/vendor/autoload.php`. Mueve la carpeta a su sitio.

---

## 6. Crear el archivo `.env`

Este archivo guarda las contraseñas. Es el más delicado de todos.

1. File Manager → entra en `/home/USUARIO/repositories/comunitarios`.
2. Arriba a la derecha, **Settings** → marca **Show Hidden Files (dotfiles)** →
   *Save*. Sin esto no verás los archivos que empiezan por punto.
3. **+ File** → nómbralo exactamente **`.env`** → *Create New File*.
4. Clic derecho sobre `.env` → **Edit** → *Edit* otra vez si avisa de la
   codificación.
5. Abre `.env.production.example` del repositorio, **copia todo su contenido** y
   pégalo en el editor.
6. Rellena los huecos marcados con `← rellenar`:

| Clave | Qué poner |
|---|---|
| `APP_KEY` | Déjala vacía, la generamos en el paso 7 |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Los tres valores del paso 2 |
| `MP_ACCESS_TOKEN`, `MP_PUBLIC_KEY` | Credenciales **de producción** de Mercado Pago (`APP_USR-…`) |
| `MP_WEBHOOK_SECRET` | Lo tendrás en el paso 11. De momento, vacío |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | Los de la cuenta de correo, si ya existe |
| `CAMPANA_META` | La meta de recaudación, si tesorería ya la decidió. Vacía si no |

7. **Save Changes**.
8. Clic derecho sobre `.env` → **Permissions** → escribe **600** → *Change
   Permissions*.

> **600 significa**: solo tu cuenta puede leer este archivo. Es lo que impide
> que otro cliente del mismo servidor compartido lo abra.

---

## 7. Generar la clave de la aplicación (`APP_KEY`)

Esta clave cifra las sesiones. **Tiene que ser nueva y única de este servidor**;
no se copia de ningún sitio.

### Si tienes Terminal en cPanel (lo más fácil)

cPanel → **Terminal**:

```bash
cd /home/USUARIO/repositories/comunitarios
/opt/cpanel/ea-php83/root/usr/bin/php artisan key:generate --force
```

Debe responder `INFO  Application key set successfully.`

### Si tu plan no tiene Terminal

cPanel → **Git™ Version Control** → tu repositorio → pestaña **Pull or Deploy**.
Ese botón ejecuta las tareas del archivo `.cpanel.yml`, y ahí ya hay comandos
como este. Pídele a quien programa que añada **temporalmente** la línea
`key:generate --force`, pulsa *Deploy HEAD Commit*, y que la quite después.

**Nunca** subas un archivo PHP a `public/` para generar la clave: mientras esté
ahí, cualquiera puede abrirlo.

**Comprobar:** abre el `.env` y mira que `APP_KEY=` ahora tenga un valor largo
que empieza por `base64:`.

---

## 8. Dar permisos de escritura

cPanel → Terminal:

```bash
cd /home/USUARIO/repositories/comunitarios
chmod -R 775 storage bootstrap/cache

# Comprobantes del canal QR: FUERA de public/, nadie los ve por URL.
mkdir -p storage/app/private/comprobantes
chmod 775 storage/app/private/comprobantes

# Portadas de los fondos que se suben desde el panel: estas SÍ se ven.
mkdir -p public/uploads/fondos
chmod 775 public/uploads/fondos
```

Sin Terminal: en File Manager, clic derecho sobre cada carpeta →
**Permissions** → `775` → marca **Recurse into subdirectories**.

**Comprobar que el servidor puede escribir:**

```bash
touch public/uploads/fondos/prueba.txt && rm public/uploads/fondos/prueba.txt && echo "escritura OK"
```

Si dice *Permission denied*, el dueño de la carpeta no es tu usuario:
`chown -R $(whoami) storage public/uploads bootstrap/cache`.

---

## 9. Apuntar el dominio a la carpeta correcta

cPanel → **Domains** → en la fila de `comunitarios.org`, **Manage**.

En **Document Root**, escribe:

```
/home/USUARIO/repositories/comunitarios/public
```

⚠️ Con **`/public` al final**. Es el paso que más se falla. Si apuntas el
dominio a `/home/USUARIO/repositories/comunitarios` a secas, el archivo de contraseñas queda
publicado en internet.

Marca también **Force HTTPS Redirect** y guarda.

**Comprobar:** abre `https://comunitarios.org`. Debe cargar la portada.
Si ves un error 500, ve al paso 14.

---

### ⚠️ Esta instalación tiene dos carpetas públicas

En cPanel, el Document Root de `comunitarios.org` es **`~/public_html`**, no el
`public/` del repositorio. La aplicación se ejecuta desde
`~/repositories/comunitarios`, pero lo que sirve el navegador —el CSS, el
JavaScript, las imágenes— sale de `~/public_html`.

Eso significa que **actualizar el código no basta**: si `~/public_html/build/`
se queda con los archivos compilados de una versión anterior, el navegador pide
un CSS que ya no existe y Laravel responde con un **500 en todo el sitio**, con
este mensaje en el registro:

```
Unable to locate file in Vite manifest: resources/js/publico.js
```

Pasó el 28/09/2026. Por eso el `.cpanel.yml` incluye ahora dos tareas de
`rsync` que sincronizan `build/` y `media/` en cada despliegue.

**Si alguna vez vuelve a pasar**, esto lo arregla a mano:

```bash
cd ~/repositories/comunitarios
rsync -a --delete public/build/ ~/public_html/build/
```

**La solución de fondo** es apuntar el Document Root directamente a
`~/repositories/comunitarios/public`. Entonces desaparece la duplicidad y las
dos tareas de `rsync` se pueden borrar del `.cpanel.yml`. Antes de hacerlo hay
que mirar qué contiene `~/public_html/index.php`, porque en esta instalación
redefine la carpeta pública de Laravel y ese ajuste se perdería.

---

## 10. Crear el primer administrador

El panel no tiene registro abierto: sin este paso nadie puede entrar.

cPanel → Terminal:

```bash
cd /home/USUARIO/repositories/comunitarios
/opt/cpanel/ea-php83/root/usr/bin/php artisan make:superadmin
```

Te pedirá usuario y contraseña (mínimo 12 caracteres; no se ve al escribirla).

> La contraseña **no está** en el repositorio ni en el `.sql`, a propósito. Si
> se pierde, se crea otro usuario con este mismo comando.

Entra en `https://comunitarios.org/admin`. Desde ahí ya se crean los demás
administradores sin volver a la consola.

### Los dos roles

| Rol | Puede |
|---|---|
| **Superadministrador** | Todo: crear y borrar fondos, publicarlos, elegir el preseleccionado y gestionar administradores |
| **Editor** | Solo editar textos e imágenes de fondos que ya existen |

Un editor **no** puede publicar un fondo. Es deliberado: publicar es decidir que
la organización va a pedir dinero para algo, y esa decisión no es de quien
redacta. Por eso publicar pide escribir el nombre exacto del fondo para
confirmarlo.

---

## 11. Registrar el webhook en Mercado Pago

El webhook es la llamada que Mercado Pago le hace al sitio cuando un pago se
aprueba. Sin él, las donaciones tardarían en confirmarse.

1. Entra en <https://www.mercadopago.com.pe/developers> → **Tus integraciones**
   → tu aplicación → **Webhooks**, en modo **Producción**.
2. URL: `https://comunitarios.org/api/webhooks/mercadopago`
3. Eventos: marca **Pagos**. Opcionalmente, *Órdenes comerciales*.
4. Guarda. Mercado Pago te muestra una **clave secreta**: cópiala en
   `MP_WEBHOOK_SECRET` del `.env`.
5. Vuelve a cachear la configuración:
   ```bash
   cd /home/USUARIO/repositories/comunitarios
   /opt/cpanel/ea-php83/root/usr/bin/php artisan config:cache
   ```

> La clave secreta **solo se ve una vez**. Si la pierdes, hay que regenerar el
> webhook y volver a copiarla.
>
> Sin `MP_WEBHOOK_SECRET` y con `APP_ENV=production`, el sistema **rechaza todas
> las notificaciones con un 401**, a propósito: es preferible que Mercado Pago
> reintente a aceptar avisos sin firmar que cualquiera podría falsificar.

Diagnóstico detallado del webhook: [runbook-webhook.md](runbook-webhook.md).

---

## 12. Programar la tarea automática

cPanel → **Cron Jobs**. En *Add New Cron Job*:

- **Common Settings**: *Once Per Minute*, o a mano: `* * * * *`
- **Command**:

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/USUARIO/repositories/comunitarios/artisan schedule:run >> /dev/null 2>&1
```

**Add New Cron Job**.

> **Se ejecuta cada minuto, pero casi siempre no hace nada.** Así funciona el
> programador de Laravel: se despierta, mira si toca alguna tarea y se va. Hoy
> solo hay una tarea, a las 3:30 de la madrugada, que compara los contadores de
> cada fondo con las donaciones reales y **anota** las diferencias en
> `storage/logs/conciliacion.log`. No corrige nada por su cuenta: si los números
> se descuadraran, arreglarlos automáticamente cada noche escondería el motivo.

---

## 13. Comprobaciones finales

Hazlas todas antes de anunciar el sitio.

| # | Qué probar | Qué debe pasar |
|---|---|---|
| 1 | `https://comunitarios.org` | Carga la portada, con estilos |
| 2 | `https://comunitarios.org/donar` | Se ven los fondos y el formulario |
| 3 | `https://comunitarios.org/api/dashboard` | Devuelve datos en formato JSON |
| 4 | **`https://comunitarios.org/.env`** | **Error 404.** Si descarga un archivo, PARA TODO: vuelve al paso 9, el Document Root está mal, y cambia inmediatamente todas las contraseñas y las credenciales de Mercado Pago |
| 5 | `https://comunitarios.org/admin` | Pide usuario y contraseña |
| 6 | Candado del navegador | HTTPS, sin avisos |
| 7 | Una donación real de S/ 5 con tu propia tarjeta | Llegas al checkout, pagas, y vuelves a una pantalla que confirma. El fondo suma S/ 5 en el panel |

> La prueba 7 es la única que demuestra que todo el circuito funciona. **Hazla
> con tu tarjeta y luego devuélvete el dinero desde el panel de Mercado Pago.**
> Cinco soles son más baratos que descubrir el fallo con la donación de otra
> persona.

Comprueba también que `https://comunitarios.org/api/dashboard` **no** muestra
correos, documentos ni teléfonos. Solo nombres abreviados tipo «María P.» o
«Donante anónimo».

---

## 14. Si sale un error 500

Un 500 es «algo falló y no te voy a decir qué». Es correcto que no lo diga en
público. El motivo está en el registro:

```bash
tail -n 50 /home/USUARIO/repositories/comunitarios/storage/logs/laravel.log
```

Sin Terminal: File Manager → `storage/logs` → clic derecho sobre el archivo →
**View**.

**Las tres causas, por orden de frecuencia:**

**1. Falta la `APP_KEY`** · *No application encryption key has been specified*
→ Te saltaste el paso 7. Genérala y vuelve a cachear:
```bash
php artisan key:generate --force && php artisan config:cache
```

**2. Permisos** · *failed to open stream: Permission denied* o *The stream or
file … could not be opened*
→ Laravel no puede escribir en `storage`. Repite el paso 8.

**3. Caché de configuración vieja** · *Access denied for user* o *Unknown
database*, aunque los datos del `.env` sean correctos
→ Cambiaste el `.env` después de cachear. La caché manda sobre el archivo:
```bash
php artisan config:clear && php artisan config:cache
```

> **Nunca pongas `APP_DEBUG=true` para ver el error.** Esa pantalla muestra el
> contenido del `.env` —incluidas las credenciales de Mercado Pago— a cualquiera
> que provoque el fallo. El registro dice lo mismo, en privado.

Si no es ninguna de las tres, copia las últimas 50 líneas del registro y pásalas
a quien programa. La línea que importa es la primera, no la lista larga de
después.

---

## 15. Volver atrás si algo sale mal

**Si el sitio se queda en «En mantenimiento»**, porque un despliegue falló a
medias:

```bash
cd /home/USUARIO/repositories/comunitarios
/opt/cpanel/ea-php83/root/usr/bin/php artisan up
```

**Si la versión nueva está rota y quieres la anterior:**

```bash
cd /home/USUARIO/repositories/comunitarios
git log --oneline -5          # anota el código de la versión que sí funcionaba
git checkout <codigo>
php artisan config:clear && php artisan config:cache && php artisan up
```

`vendor/` y `public/build/` no cambian al volver atrás, así que no hay que
volver a subir los zips salvo que la versión rota trajera librerías nuevas.

**Si el problema está en la base de datos**, restaura la copia:
phpMyAdmin → selecciona la base → **Importar** → el `.sql` de la copia.

> ⚠️ Restaurar una copia **borra las donaciones registradas desde que se hizo**.
> Antes de restaurar, exporta la tabla `donaciones` por separado para no perder
> ningún aporte real.

### Copias de seguridad

Antes de cada despliegue, y como mínimo una vez por semana:

cPanel → **Backup Wizard** → *Back Up* → *MySQL Databases* → descarga el `.sql`.

Guarda esa copia **fuera del servidor**. Una copia que vive en el mismo sitio
que el original no es una copia de seguridad.

---

# Pasar de sandbox a producción

Cuando el sitio se probó con las credenciales de prueba y llega el momento de
cobrar de verdad, se tocan **siete claves** del `.env`. No hay que cambiar nada
del código.

| # | Clave | En pruebas | En producción |
|---|---|---|---|
| 1 | `MP_ENV` | `sandbox` | `production` |
| 2 | `MP_ACCESS_TOKEN` | `TEST-…` | `APP_USR-…` |
| 3 | `MP_PUBLIC_KEY` | `TEST-…` | `APP_USR-…` |
| 4 | `MP_WEBHOOK_SECRET` | la del webhook de prueba | **la del webhook de producción** |
| 5 | `MP_USE_SANDBOX_INIT_POINT` | `true` | `false` |
| 6 | `MP_SANDBOX_PAYER_EMAIL` | el correo de un usuario de prueba | **vacío** |
| 7 | `MP_WEBHOOK_URL` | la URL de pruebas | `https://comunitarios.org/api/webhooks/mercadopago` |

**Después de cambiarlas, siempre:**

```bash
cd /home/USUARIO/repositories/comunitarios
/opt/cpanel/ea-php83/root/usr/bin/php artisan config:clear
/opt/cpanel/ea-php83/root/usr/bin/php artisan config:cache
```

Sin esto, el sitio sigue cobrando con las credenciales viejas: manda la caché,
no el archivo.

### Las cuatro trampas de este cambio

**El `MP_WEBHOOK_SECRET` es distinto por aplicación.** No es «la clave de tu
cuenta»: Mercado Pago genera una por cada webhook que registras. La de sandbox
**no vale** en producción. Si la dejas puesta, todas las notificaciones reales
se rechazan con un 401 y las donaciones se quedan en «pendiente» hasta que
alguien lo mira.

**`MP_SANDBOX_PAYER_EMAIL` tiene que quedar vacío.** Si sobrevive del entorno de
pruebas, sustituye el correo real del donante por el de un usuario de prueba, y
el comprobante de Mercado Pago se va a un buzón que nadie lee.

**No mezcles credenciales.** Las dos, `MP_ACCESS_TOKEN` y `MP_PUBLIC_KEY`, de la
misma aplicación y del mismo tipo. Una de producción con otra de pruebas produce
un «Algo salió mal» en el checkout que no explica nada.

**Vuelve a registrar el webhook en modo Producción.** El panel de Mercado Pago
tiene dos modos y el webhook de pruebas no se copia solo. Repite el paso 11 con
el selector en *Producción*.

---

## Para quien programa: regenerar el `.sql` más adelante

Cuando haya migraciones nuevas, el esquema se vuelve a generar desde una base
local ya migrada:

```bash
mysqldump --no-data --routines --skip-comments --single-transaction \
          --no-tablespaces --default-character-set=utf8mb4 comunitarios
```

Y sobre esa salida hay que:

1. Quitar **todo `DEFINER=`** y dejar la vista con `SQL SECURITY INVOKER`.
   En hosting compartido el usuario del volcado no existe y el import falla con
   *access denied; you need SUPER privileges*.
2. Quitar los `AUTO_INCREMENT=<n>` heredados, para que una instalación nueva
   empiece a contar en 1.
3. Quitar cualquier `CREATE DATABASE` o `USE`: en cPanel la base ya existe y se
   llama distinto.
4. Añadir los `INSERT` de la tabla `migrations` con **todas** las aplicadas. Sin
   ellos, `php artisan migrate` intenta crear tablas que ya existen y se cae.
5. Añadir el `INSERT` del fondo inicial, con los contadores a cero.
6. **No incluir ningún administrador ni ningún hash de contraseña.**

Y luego **probarlo de verdad**: importarlo en una base vacía y comprobar que
`php artisan migrate --pretend` responde *Nothing to migrate*.
