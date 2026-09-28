# Despliegue en cPanel

Guía paso a paso para poner comunitarios.org en producción. Está escrita para
que la pueda seguir alguien que no programa: cada paso dice **qué hacer**, **qué
deberías ver** y **qué hacer si sale mal**.

Reserva una hora la primera vez. Ten a mano:

- El acceso a cPanel (usuario y contraseña).
- El archivo `database/sql/comunitarios-schema.sql` de este repositorio.
- El archivo `.env.production.example` de este repositorio.
- Las credenciales de producción de Mercado Pago.

---

## 1. Preparar PHP

cPanel → **Select PHP Version**.

1. Elige **PHP 8.3** (mínimo 8.2).
2. En la pestaña de extensiones, marca: `curl`, `mbstring`, `openssl`,
   `pdo_mysql`, `fileinfo`, `zip`, `intl`.
3. Guarda.

> Si falta `intl`, algunas pantallas de administración fallan al formatear
> números. Si falta `curl`, no se puede hablar con Mercado Pago.

---

## 2. Subir el proyecto y apuntar el DocumentRoot

**Esto es lo que más se equivoca, y es lo que más daño hace.**

El proyecto NO se sube a `public_html`. Se sube a una carpeta hermana, y
`public_html` apunta a la subcarpeta `public/` del proyecto:

```
/home/USUARIO/
├── comunitarios_app/          ← todo el proyecto va aquí
│   ├── app/  bootstrap/  config/  database/  resources/  routes/  storage/  vendor/
│   ├── public/               ← solo ESTO debe ser accesible por web
│   └── .env
└── public_html/              ← apunta a comunitarios_app/public
```

Si `public_html` apunta a la raíz del proyecto, **cualquiera puede descargar tu
`.env` con las credenciales de Mercado Pago escribiendo la URL**. No es una
exageración: es el fallo más común en hosting compartido.

Formas de hacerlo bien, de mejor a peor:

1. **Document Root del dominio** (cPanel → *Domains* → *Manage*): cámbialo a
   `/home/USUARIO/comunitarios_app/public`. Es lo limpio.
2. Si el hosting no te deja cambiarlo, borra `public_html` y créalo como enlace
   simbólico:
   `ln -s /home/USUARIO/comunitarios_app/public /home/USUARIO/public_html`

**Cómo comprobar que está bien:** abre `https://comunitarios.org/.env` en el
navegador. Debe dar **404**. Si te descarga un archivo, para todo y arregla esto
antes de seguir.

---

## 3. Crear la base de datos

cPanel → **MySQL® Databases**.

1. *Create New Database*: `comunitarios`.
   cPanel le pondrá delante el prefijo de tu cuenta: quedará
   `USUARIO_comunitarios`. **Apunta el nombre completo.**
2. *Add New User*: crea un usuario dedicado con una contraseña larga generada
   por cPanel. **Apúntala.**
3. *Add User To Database*: añade el usuario a la base con **ALL PRIVILEGES**.

---

## 4. Importar el esquema

cPanel → **phpMyAdmin** → selecciona `USUARIO_comunitarios` en la izquierda →
pestaña **Importar** → elige `database/sql/comunitarios-schema.sql` → **Continuar**.

Deberías ver *"La importación se ejecutó correctamente"* y, en la izquierda,
**15 objetos**: 14 tablas más la vista `v_fondos_conciliacion`.

Comprueba en la pestaña **SQL**:

```sql
SELECT COUNT(*) FROM migrations;   -- debe dar 14
SELECT slug, nombre, estado FROM fondos;   -- debe dar fundacion-antonia
SELECT COUNT(*) FROM admin_users;  -- debe dar 0
```

Que `admin_users` esté vacío es correcto y buscado: el archivo no trae ningún
usuario ni ninguna contraseña. El administrador se crea en el paso 8.

**Si el import falla:**

| Error | Causa | Solución |
|---|---|---|
| `access denied; you need SUPER privileges` | El `.sql` traía un `DEFINER` | Este archivo ya viene sin él. Si lo regeneraste tú, quítalo (ver §11) |
| `Unknown database` | Elegiste la base equivocada | Selecciónala en la izquierda antes de importar |
| `Table already exists` | La base no estaba vacía | Bórrala y créala de nuevo |

---

## 5. Subir el `.env` y protegerlo

1. Copia `.env.production.example` a tu ordenador y renómbralo a `.env`.
2. Rellénalo. Los tres valores de base de datos son los del paso 3
   (`DB_DATABASE` lleva el prefijo del usuario). `APP_KEY` se deja **vacío**:
   se genera en el paso 6.
3. Súbelo a `/home/USUARIO/comunitarios_app/.env` (la raíz del proyecto, **no**
   dentro de `public/`).
4. cPanel → *File Manager* → clic derecho sobre `.env` → *Change Permissions* →
   pon **600** (solo lectura y escritura para el dueño).

---

## 6. Generar la clave de la aplicación

cPanel → **Terminal** (o por SSH):

```bash
cd ~/comunitarios_app
php artisan key:generate --force
```

Debe responder `Application key set successfully`.

> **Nunca copies el `APP_KEY` de tu equipo local.** Cifra sesiones y datos: si
> se comparte entre entornos, una sesión de desarrollo vale en producción.

---

## 7. Cachear configuración y rutas

```bash
cd ~/comunitarios_app
php artisan config:cache
php artisan route:cache
```

**Repite estos dos comandos cada vez que cambies el `.env`.** Con la
configuración cacheada, editar el `.env` no tiene ningún efecto hasta que se
regenera la caché. Es la causa número uno de "he cambiado la credencial y sigue
fallando".

---

## 8. Crear el primer administrador

```bash
cd ~/comunitarios_app
php artisan make:superadmin
```

Te pedirá usuario y contraseña (mínimo 12 caracteres; no se ve al escribirla).

> La contraseña no está en ningún archivo del repositorio ni en el `.sql`. Si se
> pierde, se crea otro usuario con este mismo comando.

**Este primer usuario hay que crearlo por consola sí o sí**: el panel no tiene
registro abierto, y sin ningún administrador nadie puede entrar. A partir de
ahí, los demás se crean desde el propio panel.

### Entrar al panel

Una vez creado, el panel está en:

```
https://comunitarios.org/admin
```

Entrando con ese usuario se puede crear un fondo, subirle imágenes, publicarlo y
ver cuánto lleva recaudado, sin volver a tocar la consola.

### Los dos roles

| Rol | Puede |
|---|---|
| **Superadministrador** | Todo: crear y borrar fondos, publicarlos, elegir el preseleccionado y gestionar administradores |
| **Editor** | Solo editar textos e imágenes de fondos que ya existen |

Un editor **no** puede publicar un fondo ni decidir cuál sale preseleccionado.
Es deliberado: publicar un fondo es decidir que la organización va a pedir
dinero para algo, y esa es una decisión de la organización, no de quien redacta.

> Publicar un fondo pide escribir su nombre exacto para confirmarlo. No es un
> capricho: es el momento en que ese fondo empieza a recibir dinero real.

---

## 9. Permisos de escritura

```bash
cd ~/comunitarios_app
chmod -R 755 storage bootstrap/cache
mkdir -p storage/app/private/comprobantes
chmod 755 storage/app/private/comprobantes

# Imágenes de los fondos que se suben desde el panel.
mkdir -p public/uploads/fondos
chmod 755 public/uploads/fondos
```

`storage/` guarda registros, caché y sesiones. `storage/app/private/comprobantes`
guardará las capturas de Yape/Plin cuando exista el canal QR: está **fuera** de
`public/` a propósito, para que nadie pueda ver el comprobante bancario de un
donante escribiendo una URL.

`public/uploads/fondos` es justo lo contrario: ahí van las portadas y galerías
de los fondos, que **sí** tienen que verse. Escribe directamente dentro de
`public/` para no depender de `php artisan storage:link`, que en hosting
compartido a veces no se puede crear — y si falta, ninguna imagen del panel se
vería.

**Comprobar que el servidor puede escribir ahí:**

```bash
touch public/uploads/fondos/prueba.txt && rm public/uploads/fondos/prueba.txt && echo "escritura OK"
```

Si da *Permission denied*, el usuario de PHP no es el dueño de la carpeta:
`chown -R $(whoami) public/uploads`.

**Comprobar:** `https://comunitarios.org/` debe cargar la portada. Si ves un
error 500, mira `storage/logs/laravel.log`.

---

## 10. Registrar el webhook en Mercado Pago

1. https://www.mercadopago.com.pe/developers → **Tus integraciones** → tu
   aplicación → **Webhooks**, en modo **Producción**.
2. URL: `https://comunitarios.org/api/webhooks/mercadopago`
3. Eventos: **Pagos**. Opcionalmente **Órdenes comerciales**.
4. Guardar. Copia la **clave secreta** que muestra y ponla en `MP_WEBHOOK_SECRET`
   del `.env`.
5. Vuelve a ejecutar `php artisan config:cache`.

> Sin `MP_WEBHOOK_SECRET` y con `APP_ENV=production`, el sistema **rechaza todas
> las notificaciones con un 401** a propósito: es preferible que Mercado Pago
> reintente a aceptar notificaciones sin firmar.

Detalle completo del webhook y su diagnóstico: [runbook-webhook.md](runbook-webhook.md).

---

## 11. Verificar que funciona

En este orden:

| # | Qué | Cómo | Esperado |
|---|---|---|---|
| 1 | El `.env` no es público | Abrir `https://comunitarios.org/.env` | **404** |
| 2 | La portada carga | Abrir `https://comunitarios.org/` | La página, sin errores |
| 3 | El certificado | Mirar el candado del navegador | Válido, sin avisos |
| 4 | La base responde | `php artisan db:show` | Conecta y lista las tablas |
| 5 | Los contadores cuadran | `php artisan fondos:recalcular --dry-run` | *Todos los contadores cuadran* |
| 6 | El panel abre | `https://comunitarios.org/admin` | Formulario de acceso; entra con el usuario del paso 8 |
| 7 | Se puede publicar un fondo | Crear uno desde el panel y publicarlo | Queda `activo` y aparece en el formulario de donación |
| 8 | Una donación real | Donar S/ 5 de verdad y pagar | Queda `aprobado`, el contador del fondo sube |

La comprobación 6 es la única que prueba el circuito completo. Hazla con dinero
real y poco importe antes de anunciar la campaña.

---

## 12. Si algo sale mal: volver atrás

El despliegue automático guarda las tres últimas versiones en
`~/comunitarios_app/releases/`. Para volver a la anterior:

```bash
cd ~/comunitarios_app
ls -t releases/            # la primera es la actual
ln -sfn "$PWD/releases/<SHA_ANTERIOR>" current-next
mv -Tf current-next current
cd current && php artisan optimize:clear && php artisan optimize
```

**La base de datos no vuelve atrás sola.** Si el problema fue una migración,
haz primero una copia y estudia si conviene revertirla:

```bash
mysqldump -u USUARIO -p USUARIO_comunitarios > ~/backup-antes-de-revertir.sql
```

### Copias de seguridad

Programa esto en cPanel → *Cron Jobs*, a diario:

```bash
mysqldump -u USUARIO -p'CLAVE' USUARIO_comunitarios | gzip > ~/backups/db-$(date +\%F).sql.gz
find ~/backups -name '*.gz' -mtime +30 -delete
```

> Un backup que nunca se restauró no es un backup. Prueba la restauración
> completa en local al menos una vez antes de lanzar la campaña.

---

## 13. Regenerar el `.sql` en el futuro

Cuando haya migraciones nuevas y quieras rehacer el esquema:

```bash
php artisan migrate                      # en local, primero
mysqldump --no-data --routines --skip-comments --single-transaction \
          -u root comunitarios > database/sql/comunitarios-schema.sql
```

Y **edítalo siempre** para:

1. Quitar cualquier `CREATE DATABASE` o `USE`: en cPanel la base ya existe con
   otro nombre.
2. **Quitar el `DEFINER=` de la vista `v_fondos_conciliacion`** y dejar
   `SQL SECURITY INVOKER`. El usuario `root@localhost` del volcado no existe en
   el hosting y el import falla con *"you need SUPER privileges"*. Este es el
   paso que más despliegues rompe.
3. Añadir al final los `INSERT` de la tabla `migrations`, para que Laravel no
   intente correr otra vez lo que el archivo acaba de crear.
4. Añadir el `INSERT` del fondo inicial.
5. **No incluir ningún usuario administrador ni ningún hash de contraseña.**

Antes de darlo por bueno, pruébalo importándolo en una base vacía:

```bash
mysql -u root -e "CREATE DATABASE prueba_import"
mysql -u root prueba_import < database/sql/comunitarios-schema.sql
mysql -u root prueba_import -e "SELECT COUNT(*) FROM migrations; SELECT COUNT(*) FROM admin_users;"
mysql -u root -e "DROP DATABASE prueba_import"
```
