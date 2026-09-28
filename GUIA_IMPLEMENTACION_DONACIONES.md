# Guía de Implementación — Plataforma de Donaciones / Crowdfunding

> **Documento de transferencia técnica.**
> Describe, paso a paso y con código completo, cómo está implementada la plataforma de donaciones
> que hoy funciona en producción (Mercado Pago Checkout Pro + QR Yape/Plin + Tesorería manual +
> Panel admin + Auditoría de fondos), para replicarla íntegra en **otra organización**.
>
> **Regla de oro de este documento:**
> - El **backend, el modelo de datos y los contratos de API se copian tal cual** (es lo que hace que
>   el sistema sea confiable con dinero real).
> - El **diseño, la paleta de colores, la tipografía, el copy y el layout son 100 % de la nueva
>   organización**. La Sección 15 define exactamente la frontera entre ambos.

---

## Índice

| # | Sección |
|---|---|
| 0 | [Resumen ejecutivo y alcance](#0-resumen-ejecutivo-y-alcance) |
| 1 | [Arquitectura general](#1-arquitectura-general) |
| 2 | [Stack, requisitos y convenciones](#2-stack-requisitos-y-convenciones) |
| 3 | [Estructura de carpetas](#3-estructura-de-carpetas) |
| 4 | [Base de datos](#4-base-de-datos) |
| 5 | [Configuración: .env y bootstrap](#5-configuración-env-y-bootstrap) |
| 6 | [Utilidades compartidas](#6-utilidades-compartidas) |
| 7 | [Canal 1 — Mercado Pago (Checkout Pro)](#7-canal-1--mercado-pago-checkout-pro) |
| 8 | [Canal 2 — QR Yape/Plin con comprobante](#8-canal-2--qr-yapeplin-con-comprobante) |
| 9 | [Canal 3 — Efectivo / Tesorería manual](#9-canal-3--efectivo--tesorería-manual) |
| 10 | [API pública de datos (dashboard)](#10-api-pública-de-datos-dashboard) |
| 11 | [Autenticación de administradores](#11-autenticación-de-administradores) |
| 12 | [Panel administrativo](#12-panel-administrativo) |
| 13 | [Paquete de auditoría de fondos](#13-paquete-de-auditoría-de-fondos) |
| 14 | [Frontend público: contrato JS](#14-frontend-público-contrato-js) |
| 15 | [Capa de diseño: cómo re-skinear sin romper nada](#15-capa-de-diseño-cómo-re-skinear-sin-romper-nada) |
| 16 | [Seguridad](#16-seguridad) |
| 17 | [Despliegue](#17-despliegue) |
| 18 | [Plan de pruebas (QA)](#18-plan-de-pruebas-qa) |
| 19 | [Troubleshooting: errores reales y su solución](#19-troubleshooting-errores-reales-y-su-solución) |
| 20 | [Orden de implementación recomendado](#20-orden-de-implementación-recomendado) |

---

## 0. Resumen ejecutivo y alcance

### Qué hace el sistema

Una campaña de recaudación pública con **tres canales de ingreso de dinero** que confluyen en
**una sola tabla** y un **solo tablero de control**:

| Canal | `canal_pago` | Cómo entra | Quién lo aprueba |
|---|---|---|---|
| Mercado Pago Checkout Pro | `mercadopago` | El donante paga con tarjeta/Yape/efectivo en la pasarela | Automático (webhook) |
| QR Yape/Plin | `qr_manual` | El donante transfiere y sube su captura | Un admin revisa la imagen y aprueba |
| Efectivo / transferencia directa | `efectivo_manual` | Un admin lo registra desde tesorería | Se crea ya aprobado |

Sobre esa base se montan:

- **Dashboard público en vivo**: total recaudado, % de la meta, número de donantes, ranking por
  región/categoría, feed de donantes recientes, ticker.
- **Panel admin**: cola de verificación de QR con visor de comprobante y zoom, formulario de
  tesorería, exportación a Excel, y un **paquete ZIP de auditoría** que cruza la base de datos con
  las comisiones reales cobradas por Mercado Pago.
- **Anonimato opcional** por donante, respetado en todas las vistas públicas.

### Los tres principios que hacen que funcione

1. **`external_reference` = `id` de la donación.** Es la única llave de cruce entre la base de datos
   y Mercado Pago. Todo el sistema de recuperación de pagos depende de esto. *Nunca* lo dejes vacío.
2. **Triple red de seguridad para confirmar un pago.** Webhook (automático) → reconciliación al
   volver del checkout (el navegador del donante) → rescate por búsqueda en la auditoría. Si una
   falla, otra lo atrapa. Ninguna sola es suficiente en la práctica.
3. **`monto_referencial` ≠ `monto_real`.** Lo que el donante *dijo* que iba a dar y lo que
   *efectivamente* entró. Nunca se sobreescriben mutuamente. Toda la contabilidad usa
   `COALESCE(monto_real, monto_referencial)`.

### Qué se copia y qué se rehace

| Se copia idéntico (no lo reinventes) | Se rehace 100 % con la marca nueva |
|---|---|
| Esquema SQL y estados | Paleta de colores, tipografías, tokens CSS |
| Endpoints PHP y sus contratos JSON | HTML/layout de la landing y del formulario |
| Flujo MP (preferencia → checkout → webhook → reconcile) | Copy, textos, imágenes, logo, favicon |
| Validaciones de servidor | Diseño del panel admin (estructura de datos sí se copia) |
| Lógica de anonimato y de agregación | Taxonomía: "regiones" → lo que use la otra ONG |
| Sistema de sesiones admin | Metas, montos mínimos, moneda |
| Paquete de auditoría | — |

---

## 1. Arquitectura general

```
┌──────────────────────────── NAVEGADOR DEL DONANTE ─────────────────────────────┐
│  landing/donaciones.html                                                        │
│    ├── donation-flow.js   (formulario 2 pasos, validación, POST a la API)       │
│    └── dashboard.js       (métricas en vivo, ranking, mapa, feed) — GET cada 30s│
└─────────────┬──────────────────────────────────────────────┬───────────────────┘
              │ POST /api/donation/create.php                │ GET /api/donors/list.php
              │ POST /api/donation/qr_submit.php (multipart) │
              │ POST /api/donation/reconcile.php             │
              ▼                                              ▼
┌──────────────────────────── API PHP (Apache + PDO/MySQL) ──────────────────────┐
│  config/   env.php · database.php · mercadopago.php · categories.php            │
│  utils/    Response.php · Validator.php · Logger.php                            │
│  donation/ create.php · webhook.php · reconcile.php · qr_submit.php             │
│  donors/   list.php                            (público, sin sesión)            │
│  admin/    auth.php · login · logout · check_auth · users                       │
│            qr/list · qr/verify · cash/list · cash/save                          │
│            donations/export.php · donations/auditoria.php                       │
│  uploads/proofs/YYYY/MM/  (comprobantes QR)                                     │
│  logs/     payments.log · webhook.log · admin_*.log …                           │
└─────────┬─────────────────────────────────┬────────────────────────────────────┘
          │                                 │ sesión PHP (cookie HttpOnly)
          │ HTTPS (SDK oficial)             ▼
          ▼                    ┌──────────────────────────────┐
┌────────────────────┐         │  panel_admin.php (wrapper)   │
│   MERCADO PAGO     │         │   └── panel_admin.html       │
│  /checkout/preferences│      │        admin-panel.js        │
│  /v1/payments/{id} │         └──────────────────────────────┘
│  /merchant_orders  │
│  webhook ──────────┼──► POST /api/donation/webhook.php
└────────────────────┘
                              ┌──────────────────────────────┐
                              │   MySQL                      │
                              │   donaciones                 │
                              │   webhook_logs               │
                              │   admin_users                │
                              └──────────────────────────────┘
```

### Ciclo de vida de una donación con Mercado Pago

```
1. El usuario llena el formulario y pulsa "Donar con Mercado Pago"
2. POST create.php
     ├── valida (servidor, nunca confíes en el front)
     ├── INSERT donaciones (estado='pendiente')  ──► obtiene $donationId
     ├── crea preferencia en MP con external_reference = $donationId
     ├── UPDATE donaciones SET mp_preference_id = ...
     └── responde { init_point, preference_id, donation_id }
3. El front guarda { donation_id, preference_id } en localStorage y redirige a init_point
4. El usuario paga en Mercado Pago
5a. MP → POST webhook.php  (type=payment | merchant_order)
     ├── log en webhook_logs
     ├── GET /v1/payments/{id}
     ├── mapea status MP → estado interno
     └── UPDATE donaciones WHERE id = external_reference  ✅
5b. MP redirige al usuario a back_urls.success (?donacion=exitosa&payment_id=…)
     └── el front llama reconcile.php con lo que tenga a mano  ✅ (red de seguridad 2)
6. Si ambas fallaron (webhook caído, usuario cerró el navegador):
     auditoria.php busca en MP por external_reference y "rescata" el pago  ✅ (red 3)
```

---

## 2. Stack, requisitos y convenciones

### Stack

- **PHP 8.2+** (el SDK `mercadopago/dx-php` ^3.3 lo exige). Sin framework: PHP plano, un archivo por
  endpoint. Esto es deliberado — despliega en cualquier hosting compartido con cPanel.
- **MySQL 5.7+ / MariaDB 10.4+**, InnoDB, `utf8mb4_unicode_ci`.
- **Apache** con `mod_rewrite` y `mod_headers`.
- **Composer** (una sola dependencia).
- **Frontend**: HTML + CSS + JavaScript vanilla (ES6, sin build obligatorio). Si la otra
  organización usa React/Vue/Astro, **solo cambia la Sección 14**; la API es idéntica.

### Extensiones PHP requeridas

`curl`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `fileinfo`, `zip` (esta última solo para el
paquete de auditoría).

Verificación rápida en el servidor:

```php
<?php // api/_check.php — borrar después de usar
foreach (['curl','json','mbstring','openssl','pdo_mysql','fileinfo','zip'] as $ext) {
    printf("%-10s %s\n", $ext, extension_loaded($ext) ? 'OK' : 'FALTA');
}
echo 'PHP ' . PHP_VERSION;
```

### Convención de nombres

En el proyecto original todas las funciones globales llevan el prefijo `irf_` para no colisionar en
un hosting compartido. **En este documento uso el prefijo `app_`**. Elige el prefijo de la nueva
organización y aplícalo de forma consistente (por ejemplo `cfd_`, `fnd_`, `ong_`):

| Original | En este documento | Renombra a |
|---|---|---|
| `irf_env()` | `app_env()` | `<prefijo>_env()` |
| `irf_db()` | `app_db()` | `<prefijo>_db()` |
| `IRFADMINSESSID` | `APPADMINSESSID` | `<PREFIJO>ADMINSESSID` |
| `IRF_ENV_FILE` | `APP_ENV_FILE` | `<PREFIJO>_ENV_FILE` |

> ⚠️ El nombre de la cookie de sesión **debe ser único por organización** si ambas viven en el mismo
> dominio o subdominios hermanos. Si no, las sesiones se pisan entre sí.

### Taxonomía: "regiones" → lo que use la otra organización

El sistema original agrupa donaciones por **región del Perú** (25 regiones + "Nacional", de las
cuales solo 10 son "objetivo"). La otra organización probablemente agrupe por otra cosa:
sedes, proyectos, causas, países, líneas de trabajo.

**Llámalo `categoria` (o el nombre que corresponda) y mantén exactamente la misma mecánica:**

- Un catálogo cerrado en el servidor (`config/categories.php`).
- Un subconjunto "habilitado para donar".
- Una categoría comodín (`General` / `Nacional`) cuyo monto se **redistribuye** entre las
  habilitadas para el ranking (Sección 10).

Renombrar la columna `region` es opcional; renombrarla es más limpio, pero si tienes prisa
puedes dejar el nombre `region` y cambiar solo las etiquetas. En este documento mantengo el nombre
de columna `categoria` para dejar claro que es genérico.

---

## 3. Estructura de carpetas

```
raiz_del_proyecto/
├── .htaccess                       # seguridad global + rewrites
├── index.html                      # landing (marca nueva)
├── donaciones.html                 # página de donación (marca nueva)
├── admin_login.html                # login admin (marca nueva)
├── panel_admin.html                # panel admin (marca nueva)
├── panel_admin.php                 # ⚠️ wrapper que exige sesión antes de servir el HTML
├── admin_usuarios.html
├── admin_usuarios.php              # ⚠️ wrapper superadmin
│
├── css/
│   ├── tokens.css                  # ← TODA la paleta de la organización vive aquí
│   ├── donaciones.css
│   └── admin-panel.css
│
├── js/
│   └── modules/
│       ├── donation-flow.js        # formulario + MP + QR + reconciliación
│       ├── dashboard.js            # métricas, ranking, mapa, feed
│       └── admin-panel.js          # panel administrativo
│
├── img/
│   ├── logo.png, favicon/
│   └── donaciones/qr.png           # ← QR estático de Yape/Plin de la organización
│
├── data/
│   └── mapa.geojson                # solo si usas mapa geográfico
│
└── api/
    ├── .htaccess                   # bloquea .env, .sql, .log; fija el archivo de entorno
    ├── .env                        # NUNCA se sube a git
    ├── .env.example                # plantilla versionada
    ├── composer.json
    ├── bootstrap_db.php            # CLI: crea BD + tablas
    │
    ├── config/
    │   ├── env.php
    │   ├── database.php
    │   ├── mercadopago.php
    │   └── categories.php
    │
    ├── utils/
    │   ├── Response.php
    │   ├── Validator.php
    │   └── Logger.php
    │
    ├── donation/
    │   ├── create.php
    │   ├── webhook.php
    │   ├── reconcile.php
    │   └── qr_submit.php
    │
    ├── donors/
    │   └── list.php
    │
    ├── admin/
    │   ├── auth.php
    │   ├── login.php
    │   ├── logout.php
    │   ├── check_auth.php
    │   ├── users.php
    │   ├── qr/{list.php, verify.php}
    │   ├── cash/{list.php, save.php}
    │   └── donations/{export.php, auditoria.php}
    │
    ├── sql/schema.sql
    ├── uploads/proofs/YYYY/MM/     # comprobantes (crear con permisos de escritura)
    └── logs/                       # *.log (crear con permisos de escritura)
```

**`api/.gitignore`:**

```gitignore
.env
.env2
.env3
vendor/
logs/*.log
composer.lock
tmp/
uploads/proofs/
```

---

## 4. Base de datos

### 4.1 Esquema completo

`api/sql/schema.sql`:

```sql
-- =============================================================================
-- TABLA PRINCIPAL: una fila por intento de donación, de cualquier canal.
-- =============================================================================
CREATE TABLE IF NOT EXISTS donaciones (
  id                INT AUTO_INCREMENT PRIMARY KEY,

  -- Identidad del donante
  nombre            VARCHAR(200)    NOT NULL,
  documento         VARCHAR(20)     NOT NULL,   -- DNI (8) / RUC (11) / pasaporte
  correo            VARCHAR(200)    NOT NULL,
  telefono          VARCHAR(30)     DEFAULT NULL,
  tipo_aportante    VARCHAR(20)     NOT NULL DEFAULT 'persona',  -- persona | empresa

  -- Segmentación de campaña (antes "region")
  categoria         VARCHAR(80)     NOT NULL DEFAULT 'General',

  -- Dinero
  monto_referencial DECIMAL(10,2)   NOT NULL,   -- lo que el donante DECLARÓ
  monto_real        DECIMAL(10,2)   DEFAULT NULL, -- lo que REALMENTE entró (MP o verificación)
  moneda            VARCHAR(5)      DEFAULT 'PEN',

  -- Origen del pago
  canal_pago        VARCHAR(20)     NOT NULL DEFAULT 'mercadopago',
                                    -- mercadopago | qr_manual | efectivo_manual
  proveedor_pago    VARCHAR(30)     NOT NULL DEFAULT 'mercadopago',
                                    -- mercadopago | yape_qr | efectivo | transferencia
  referencia_pago   VARCHAR(120)    DEFAULT NULL, -- nro. de operación, voucher, nota

  -- Comprobante (canal QR)
  comprobante_path  VARCHAR(255)    DEFAULT NULL, -- uploads/proofs/2026/04/xxx.jpg
  comprobante_mime  VARCHAR(100)    DEFAULT NULL,

  -- Estado
  estado            ENUM('pendiente','aprobado','rechazado','en_proceso')
                                    DEFAULT 'pendiente',

  -- Trazabilidad Mercado Pago
  mp_preference_id  VARCHAR(200)    DEFAULT NULL,
  mp_payment_id     VARCHAR(200)    DEFAULT NULL,

  -- Consentimiento y auditoría
  visible_publico   TINYINT(1)      DEFAULT 1,  -- 0 = donante anónimo en la web
  acepta_terminos   TINYINT(1)      DEFAULT 0,
  ip_origen         VARCHAR(45)     DEFAULT NULL,

  created_at        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_estado (estado),
  INDEX idx_categoria (categoria),
  INDEX idx_canal_pago (canal_pago),
  INDEX idx_proveedor_pago (proveedor_pago),
  INDEX idx_mp_payment_id (mp_payment_id),
  INDEX idx_mp_preference_id (mp_preference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- BITÁCORA DE WEBHOOKS: guarda el payload CRUDO de cada notificación de MP.
-- Es la prueba forense cuando un pago "se pierde". Nunca la elimines.
-- =============================================================================
CREATE TABLE IF NOT EXISTS webhook_logs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  payload     TEXT          NOT NULL,
  evento      VARCHAR(100)  DEFAULT NULL,  -- payment | merchant_order | ...
  status      VARCHAR(50)   DEFAULT NULL,  -- received | aprobado | ignored | mp_error | error
  procesado   TINYINT(1)    DEFAULT 0,
  created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- USUARIOS DEL PANEL
-- =============================================================================
CREATE TABLE IF NOT EXISTS admin_users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,      -- password_hash(..., PASSWORD_BCRYPT)
  role          ENUM('superadmin','editor') DEFAULT 'editor',
  last_login    TIMESTAMP NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> ⚠️ **No hardcodees el hash del superadmin en `schema.sql`.** El proyecto original lo hace y es
> una mala práctica heredada. Crea el primer usuario con el script CLI de la Sección 11.4.

### 4.2 Máquina de estados

```
                   ┌─────────────┐
   create.php ────►│  pendiente  │
   qr_submit.php   └──────┬──────┘
                          │
        webhook / reconcile / verificación admin
                          │
        ┌─────────────────┼─────────────────┐
        ▼                 ▼                 ▼
  ┌───────────┐    ┌─────────────┐   ┌────────────┐
  │ aprobado  │    │ en_proceso  │   │ rechazado  │
  └───────────┘    └──────┬──────┘   └────────────┘
   (cuenta)               │            (no cuenta)
                    puede volver a aprobado/rechazado
```

Mapeo desde los estados de Mercado Pago (**función `map_donation_status()`, usada idéntica en
`webhook.php` y `reconcile.php`**):

| Estado MP | Estado interno |
|---|---|
| `approved` | `aprobado` |
| `rejected`, `cancelled`, `charged_back` | `rechazado` |
| todo lo demás (`pending`, `in_process`, `authorized`, `in_mediation`…) | `en_proceso` |

### 4.3 Decisión de negocio: ¿qué estados suman al total público?

El proyecto original suma `('aprobado','pendiente','en_proceso')` en el dashboard público, para que
la barra de progreso no se vea "muerta" mientras los pagos se confirman.

**Esto es una decisión de negocio, no técnica. Conviértela en un flag de entorno:**

```env
# true  → la barra incluye pagos aún no confirmados (más "vivo", menos exacto)
# false → solo dinero confirmado (recomendado para rendición de cuentas)
DASHBOARD_INCLUDE_PENDING=false
```

Y en `donors/list.php`:

```php
$includePending = filter_var((string) app_env('DASHBOARD_INCLUDE_PENDING', 'false'), FILTER_VALIDATE_BOOLEAN);
$countedStates  = $includePending ? "('aprobado','pendiente','en_proceso')" : "('aprobado')";
```

> 💡 Recomendación: si la organización va a publicar rendición de cuentas, usa `false`. Un total que
> baja porque un pago se rechazó genera desconfianza.

### 4.4 Auto-migración defensiva

El proyecto original añade columnas faltantes en caliente. Es útil cuando no tienes acceso a
migraciones en el hosting. Se ejecuta una sola vez por request (cacheado en `static`).

```php
<?php // fragmento de api/config/database.php

function app_db_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower("$table.$column");
    if (array_key_exists($key, $cache)) return (bool) $cache[$key];

    $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
    );
    $stmt->execute([':t' => $table, ':c' => $column]);
    return $cache[$key] = ($stmt->fetchColumn() !== false);
}

function app_db_has_index(PDO $pdo, string $table, string $index): bool
{
    static $cache = [];
    $key = strtolower("$table.$index");
    if (array_key_exists($key, $cache)) return (bool) $cache[$key];

    $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i LIMIT 1'
    );
    $stmt->execute([':t' => $table, ':i' => $index]);
    return $cache[$key] = ($stmt->fetchColumn() !== false);
}

/** Garantiza columnas e índices que pueden faltar en instalaciones antiguas. */
function app_db_ensure_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $columns = [
        'categoria'        => "ADD COLUMN categoria VARCHAR(80) NOT NULL DEFAULT 'General' AFTER correo",
        'telefono'         => 'ADD COLUMN telefono VARCHAR(30) DEFAULT NULL AFTER correo',
        'tipo_aportante'   => "ADD COLUMN tipo_aportante VARCHAR(20) NOT NULL DEFAULT 'persona' AFTER telefono",
        'canal_pago'       => "ADD COLUMN canal_pago VARCHAR(20) NOT NULL DEFAULT 'mercadopago' AFTER moneda",
        'proveedor_pago'   => "ADD COLUMN proveedor_pago VARCHAR(30) NOT NULL DEFAULT 'mercadopago' AFTER canal_pago",
        'referencia_pago'  => 'ADD COLUMN referencia_pago VARCHAR(120) DEFAULT NULL AFTER proveedor_pago',
        'comprobante_path' => 'ADD COLUMN comprobante_path VARCHAR(255) DEFAULT NULL AFTER referencia_pago',
        'comprobante_mime' => 'ADD COLUMN comprobante_mime VARCHAR(100) DEFAULT NULL AFTER comprobante_path',
    ];

    foreach ($columns as $column => $ddl) {
        if (!app_db_has_column($pdo, 'donaciones', $column)) {
            $pdo->exec("ALTER TABLE donaciones $ddl");
        }
    }

    foreach (['idx_categoria' => 'categoria', 'idx_canal_pago' => 'canal_pago', 'idx_proveedor_pago' => 'proveedor_pago'] as $index => $column) {
        if (!app_db_has_index($pdo, 'donaciones', $index)) {
            $pdo->exec("ALTER TABLE donaciones ADD INDEX $index ($column)");
        }
    }
}
```

Llama `app_db_ensure_schema($pdo)` justo después de `app_db()` en **todos** los endpoints que tocan
`donaciones`.

### 4.5 Script CLI de bootstrap

`api/bootstrap_db.php` — crea la base y las tablas desde cero.

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/config/env.php';

$host = (string) app_env('DB_HOST', 'localhost');
$db   = (string) app_env('DB_NAME', 'crowdfunding');
$user = (string) app_env('DB_USER', 'root');
$pass = (string) app_env('DB_PASS', '');
$safeDb = str_replace('`', '', $db);

$reset       = in_array('--reset', $argv, true);        // borra tablas, conserva la BD
$recreateDb  = in_array('--recreate-db', $argv, true);  // borra la BD entera

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    if ($recreateDb) $pdo->exec("DROP DATABASE IF EXISTS `$safeDb`");

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$safeDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$safeDb`");

    if ($reset && !$recreateDb) {
        $pdo->exec('DROP TABLE IF EXISTS webhook_logs');
        $pdo->exec('DROP TABLE IF EXISTS donaciones');
    }

    $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
    if ($sql === false) throw new RuntimeException('No se pudo leer schema.sql');

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement !== '') $pdo->exec($statement);
    }

    echo "OK: base y tablas listas en $safeDb" . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
```

```bash
php api/bootstrap_db.php                 # crear / actualizar
php api/bootstrap_db.php --reset         # recrear tablas (⚠️ borra donaciones)
php api/bootstrap_db.php --recreate-db   # recrear base completa (⚠️⚠️)
```

---

## 5. Configuración: `.env` y bootstrap

### 5.1 `api/.env.example`

```env
# ── Entorno ───────────────────────────────────────────────────────────────────
# sandbox | production  → controla si se envían datos reales del pagador a MP
APP_ENV=sandbox
APP_URL=http://localhost/mi_proyecto

# ── Mercado Pago ──────────────────────────────────────────────────────────────
# TEST-...  en sandbox | APP_USR-...  en producción
MP_PUBLIC_KEY=TEST-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MP_ACCESS_TOKEN=TEST-0000000000000000-xxxxxx-xxxxxxxxxxxxxxxxxxxxxxx-000000000

# ── Base de datos ─────────────────────────────────────────────────────────────
DB_HOST=localhost
DB_NAME=crowdfunding
DB_USER=root
DB_PASS=

# ── URLs de retorno (DEBEN ser HTTPS para que MP haga auto_return) ────────────
MP_SUCCESS_URL=https://midominio.org/donaciones.html?donacion=exitosa
MP_FAILURE_URL=https://midominio.org/donaciones.html?donacion=fallida
MP_PENDING_URL=https://midominio.org/donaciones.html?donacion=pendiente

# ── Webhook (URL pública; en local usa ngrok) ─────────────────────────────────
MP_WEBHOOK_URL=https://midominio.org/api/donation/webhook.php

# ── Ajustes de sandbox ────────────────────────────────────────────────────────
# true  → usa sandbox_init_point (credenciales TEST- clásicas)
# false → usa init_point (recomendado con credenciales APP_USR- + usuarios de prueba)
MP_USE_SANDBOX_INIT_POINT=true
MP_SANDBOX_FORCE_PAYER_TEST=false
MP_SANDBOX_PAYER_EMAIL=
MP_SANDBOX_PAYER_NAME=APRO
MP_SANDBOX_PAYER_DOC_TYPE=
MP_SANDBOX_PAYER_DOC_NUMBER=

# ── Campaña ───────────────────────────────────────────────────────────────────
DONATION_GOAL=100000
DONATION_CURRENCY=PEN
DONATION_MIN_ONLINE=50          # mínimo para el checkout de MP
DONATION_MIN_QR=10              # mínimo para el canal QR
DASHBOARD_INCLUDE_PENDING=false
```

> 🔐 `.env` **jamás** va a git. En el repositorio solo vive `.env.example` con valores falsos.
> Si alguna vez un token real se subió a git, **rótalo en el panel de Mercado Pago**: sigue siendo
> válido aunque borres el commit.

### 5.2 `api/config/env.php`

Lector de `.env` sin dependencias. Soporta múltiples archivos de entorno
(`.env` local / `.env2` producción) seleccionables por variable de servidor.

```php
<?php
declare(strict_types=1);

if (!function_exists('app_load_env')) {
    /** @return array<string, mixed> */
    function app_load_env(): array
    {
        static $config = null;
        if (is_array($config)) return $config;

        $basePath     = dirname(__DIR__);
        $envPath      = $basePath . DIRECTORY_SEPARATOR . '.env';
        $env2Path     = $basePath . DIRECTORY_SEPARATOR . '.env2';   // producción
        $fallbackPath = $basePath . DIRECTORY_SEPARATOR . '.env.example';

        $httpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $isLocalHost = $httpHost === 'localhost'
            || strpos($httpHost, 'localhost:') === 0
            || $httpHost === '127.0.0.1'
            || strpos($httpHost, '127.0.0.1:') === 0;

        // Permite fijar el archivo activo desde .htaccess con: SetEnv APP_ENV_FILE .env2
        $forcedFile = trim((string) getenv('APP_ENV_FILE'));
        $sourcePath = '';

        if ($forcedFile !== '') {
            $candidate = $forcedFile;
            if (!preg_match('/^[a-zA-Z]:\\\\|^\//', $candidate)) {
                $candidate = $basePath . DIRECTORY_SEPARATOR . ltrim($candidate, DIRECTORY_SEPARATOR);
            }
            if (file_exists($candidate)) {
                // En local siempre priorizamos .env para no usar credenciales de producción.
                $sourcePath = ($isLocalHost && file_exists($envPath)) ? $envPath : $candidate;
            }
        }

        if ($sourcePath === '') {
            if (!$isLocalHost && file_exists($env2Path))      $sourcePath = $env2Path;
            elseif (file_exists($envPath))                     $sourcePath = $envPath;
            elseif (file_exists($env2Path))                    $sourcePath = $env2Path;
            else                                               $sourcePath = $fallbackPath;
        }

        if (!file_exists($sourcePath)) {
            throw new RuntimeException('No se encontró archivo de entorno en api/.env ni api/.env.example');
        }

        $config = [];
        $lines = file($sourcePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) throw new RuntimeException('No se pudo leer la configuración de entorno.');

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') continue;

            $pos = strpos($trimmed, '=');
            if ($pos === false) continue;

            $key   = trim(substr($trimmed, 0, $pos));
            $value = trim(substr($trimmed, $pos + 1));
            if ($key === '') continue;

            // Quita comillas envolventes
            if (strlen($value) >= 2) {
                $first = $value[0]; $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $lower = strtolower($value);
            if ($lower === 'true')  { $config[$key] = true;  continue; }
            if ($lower === 'false') { $config[$key] = false; continue; }
            if ($lower === 'null')  { $config[$key] = null;  continue; }
            if (preg_match('/^-?\d+(\.\d+)?$/', $value)) {
                $config[$key] = strpos($value, '.') !== false ? (float) $value : (int) $value;
                continue;
            }

            $config[$key] = $value;
        }

        return $config;
    }
}

if (!function_exists('app_env')) {
    /** @param mixed $default @return mixed */
    function app_env(string $key, $default = null)
    {
        $config = app_load_env();
        return array_key_exists($key, $config) ? $config[$key] : $default;
    }
}
```

### 5.3 `api/config/database.php`

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/env.php';

if (!function_exists('app_db')) {
    /** Conexión PDO única por request. */
    function app_db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) return $pdo;

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            (string) app_env('DB_HOST', 'localhost'),
            (string) app_env('DB_NAME', 'crowdfunding')
        );

        $pdo = new PDO($dsn, (string) app_env('DB_USER', 'root'), (string) app_env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // ⚠️ prepared statements reales
        ]);

        return $pdo;
    }
}

// … aquí van app_db_has_column / app_db_has_index / app_db_ensure_schema (Sección 4.4)
```

### 5.4 `api/config/mercadopago.php`

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/env.php';

$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    throw new RuntimeException('Falta vendor/autoload.php. Ejecuta "composer install" dentro de api/.');
}
require_once $autoloadPath;

use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\MerchantOrder\MerchantOrderClient;
use MercadoPago\MercadoPagoConfig;

if (!function_exists('app_mp_bootstrap')) {
    function app_mp_bootstrap(): void
    {
        static $ready = false;
        if ($ready) return;

        $accessToken = (string) app_env('MP_ACCESS_TOKEN', '');
        if ($accessToken === '') {
            throw new RuntimeException('MP_ACCESS_TOKEN no está configurado en api/.env');
        }

        MercadoPagoConfig::setAccessToken($accessToken);
        $ready = true;
    }
}

if (!function_exists('app_mp_preference_client')) {
    function app_mp_preference_client(): PreferenceClient
    {
        app_mp_bootstrap();
        return new PreferenceClient();
    }
}

if (!function_exists('app_mp_payment_client')) {
    function app_mp_payment_client(): PaymentClient
    {
        app_mp_bootstrap();
        return new PaymentClient();
    }
}

if (!function_exists('app_mp_merchant_order_client')) {
    function app_mp_merchant_order_client(): MerchantOrderClient
    {
        app_mp_bootstrap();
        return new MerchantOrderClient();
    }
}
```

`api/composer.json`:

```json
{
  "name": "org/donaciones-api",
  "description": "Backend de donaciones",
  "type": "project",
  "require": {
    "mercadopago/dx-php": "^3.3"
  },
  "config": {
    "sort-packages": true
  }
}
```

```bash
cd api && composer install
```

### 5.5 `api/config/categories.php`

Catálogo cerrado de categorías/regiones. **Esta es la pieza que la otra organización adapta a su
propia taxonomía.** La estructura de funciones debe mantenerse: el resto del código las llama.

```php
<?php
declare(strict_types=1);

/** Catálogo completo: CLAVE_NORMALIZADA => Etiqueta bonita. */
function app_categories_catalog(): array
{
    return [
        'GENERAL'   => 'General',          // ← comodín, SIEMPRE presente
        'EDUCACION' => 'Educación',
        'SALUD'     => 'Salud',
        'AMBIENTE'  => 'Medio Ambiente',
        // … la taxonomía de la nueva organización
    ];
}

/** Etiquetas de todo el catálogo. */
function app_categories_labels(): array
{
    return array_values(app_categories_catalog());
}

/** Subconjunto que ACEPTA donaciones ahora mismo (campaña activa). */
function app_target_category_keys(): array
{
    return ['EDUCACION', 'SALUD', 'AMBIENTE'];
}

/** Claves del catálogo que NO aceptan donaciones (para mostrarlas apagadas en la UI). */
function app_unavailable_category_keys(): array
{
    $available = array_fill_keys(array_merge(app_target_category_keys(), ['GENERAL']), true);
    return array_values(array_filter(
        array_keys(app_categories_catalog()),
        static fn (string $key): bool => !isset($available[$key])
    ));
}

/** Etiquetas donables (las del target + el comodín). */
function app_donatable_categories_labels(): array
{
    $catalog = app_categories_catalog();
    $labels  = [];
    foreach (array_merge(app_target_category_keys(), ['GENERAL']) as $key) {
        if (isset($catalog[$key])) $labels[] = $catalog[$key];
    }
    return $labels;
}

function app_is_donatable_category(string $value): bool
{
    $key = app_normalize_category_key($value);
    return $key !== ''
        && array_key_exists($key, app_categories_catalog())
        && !in_array($key, app_unavailable_category_keys(), true);
}

/** Normaliza: mayúsculas, sin tildes, sin espacios dobles. */
function app_normalize_category_key(string $value): string
{
    $normalized = trim($value);
    if ($normalized === '') return '';

    $normalized = mb_strtoupper($normalized, 'UTF-8');
    $normalized = strtr($normalized, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
    ]);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = is_string($normalized) ? trim($normalized) : '';

    // Sinónimos que caen al comodín
    if (in_array($normalized, ['OTRO', 'OTROS', 'GENERAL', 'SIN CATEGORIA'], true)) {
        return 'GENERAL';
    }

    return $normalized;
}

/** Convierte cualquier entrada del usuario en la etiqueta canónica del catálogo. */
function app_resolve_category_label(string $value, string $fallback = ''): string
{
    $catalog = app_categories_catalog();
    $key = app_normalize_category_key($value);
    return ($key !== '' && array_key_exists($key, $catalog)) ? $catalog[$key] : $fallback;
}
```

> ⚠️ **Nunca guardes en la BD el texto crudo que envió el navegador.** Siempre pasa por
> `app_resolve_category_label()`. Así los agregados por categoría nunca se fragmentan por tildes,
> mayúsculas o espacios.

---

## 6. Utilidades compartidas

### 6.1 `api/utils/Response.php`

Respuestas JSON uniformes + CORS. **Todos** los endpoints responden con la misma forma:

```json
{ "success": true,  "…datos…" }
{ "success": false, "error": "Mensaje para el usuario" }
```

```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/env.php';

final class Response
{
    public static function handleOptions(): void
    {
        self::applyCors();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): void
    {
        self::applyCors();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($encoded === false) {
            http_response_code(500);
            $encoded = '{"success":false,"error":"No se pudo codificar la respuesta JSON."}';
        }

        echo $encoded;
        exit;
    }

    /** @param array<string, mixed> $data */
    public static function success(array $data = [], int $status = 200): void
    {
        self::json(array_merge(['success' => true], $data), $status);
    }

    /** @param array<string, mixed> $extra */
    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json(array_merge(['success' => false, 'error' => $message], $extra), $status);
    }

    private static function applyCors(): void
    {
        header('Access-Control-Allow-Origin: ' . self::resolveAllowedOrigin());
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }

    /**
     * Devuelve el Origin permitido. En producción solo el dominio de APP_URL;
     * en local se permite localhost/127.0.0.1 para el servidor de desarrollo.
     */
    private static function resolveAllowedOrigin(): string
    {
        $appUrl = (string) app_env('APP_URL', '');
        $appOrigin = '';

        if ($appUrl !== '') {
            $parts = parse_url($appUrl);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $appOrigin = $parts['scheme'] . '://' . $parts['host'];
                if (isset($parts['port'])) $appOrigin .= ':' . $parts['port'];
            }
        }

        $requestOrigin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($requestOrigin !== '') {
            $isLocal = str_contains($requestOrigin, 'localhost') || str_contains($requestOrigin, '127.0.0.1');
            if ($isLocal || $requestOrigin === $appOrigin) return $requestOrigin;
        }

        return $appOrigin !== '' ? $appOrigin : '*';
    }
}
```

> ⚠️ **Endurecer para producción:** el fallback `'*'` combinado con
> `Access-Control-Allow-Credentials: true` es inválido para el navegador y, peor, si `APP_URL` está
> mal configurado deja la API abierta. En producción reemplaza el `return '*'` por una lista blanca
> explícita:
>
> ```php
> $allowed = ['https://midominio.org', 'https://www.midominio.org'];
> return in_array($requestOrigin, $allowed, true) ? $requestOrigin : $allowed[0];
> ```

### 6.2 `api/utils/Validator.php`

**Toda validación relevante ocurre en el servidor.** La del navegador es solo para UX.

```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/categories.php';

final class Validator
{
    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, errors: array<int, string>, data: array<string, mixed>}
     */
    public static function validateDonationPayload(array $input, float $minAmount = 5.0): array
    {
        $errors = [];
        $data   = [];

        // Nombre
        $data['nombre'] = self::sanitizeText((string) ($input['nombre'] ?? ''));
        if (mb_strlen($data['nombre']) < 3 || mb_strlen($data['nombre']) > 200) {
            $errors[] = 'El nombre debe tener entre 3 y 200 caracteres.';
        }

        // Documento (DNI / RUC / pasaporte)
        $documento = preg_replace('/\s+/', '', (string) ($input['documento'] ?? ''));
        $data['documento'] = strtoupper((string) $documento);
        if (!preg_match('/^[A-Z0-9\-]{5,20}$/', $data['documento'])) {
            $errors[] = 'Documento inválido. Usa entre 5 y 20 caracteres alfanuméricos.';
        }

        // Correo
        $data['correo'] = strtolower(trim((string) ($input['correo'] ?? '')));
        if (!filter_var($data['correo'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Correo electrónico inválido.';
        }

        // Categoría (contra catálogo cerrado)
        $data['categoria'] = app_resolve_category_label((string) ($input['categoria'] ?? ''), '');
        if ($data['categoria'] === '' || !app_is_donatable_category($data['categoria'])) {
            $errors[] = 'Selecciona una categoría válida para tu donación.';
        }

        // Monto
        $amountRaw = $input['monto'] ?? null;
        if ($amountRaw === null || $amountRaw === '') {
            $errors[] = 'Debes ingresar un monto para donar.';
        }
        $amount = is_string($amountRaw) ? (float) str_replace(',', '.', $amountRaw) : (float) $amountRaw;
        $data['monto'] = round($amount, 2);

        if (!is_finite($data['monto']) || $data['monto'] < $minAmount) {
            $errors[] = 'El monto mínimo es ' . number_format($minAmount, 2, '.', '');
        }
        if ($data['monto'] > 100000) {
            $errors[] = 'El monto máximo por transacción es 100,000.00';
        }

        // Moneda
        $currency = strtoupper(trim((string) ($input['moneda'] ?? 'PEN')));
        $data['moneda'] = $currency;
        if (!in_array($currency, ['PEN', 'USD'], true)) {
            $errors[] = 'Moneda no soportada.';
        }

        // Consentimientos
        $visible = filter_var($input['visible_publico'] ?? true,  FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $terms   = filter_var($input['acepta_terminos'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $data['visible_publico'] = $visible !== false;
        $data['acepta_terminos'] = $terms === true;

        if (!$data['acepta_terminos']) {
            $errors[] = 'Debes aceptar los términos y la política de privacidad.';
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'data' => $data];
    }

    /** Heurística para el tipo de documento que espera Mercado Pago. */
    public static function guessDocumentType(string $document): string
    {
        $len = strlen($document);
        if ($len === 8 && ctype_digit($document))                       return 'DNI';
        if ($len >= 6 && $len <= 12 && preg_match('/^[A-Z0-9]+$/', $document)) return 'PASSPORT';
        return 'OTHER';
    }

    private static function sanitizeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value);
        return (string) strip_tags((string) $value);
    }
}
```

### 6.3 `api/utils/Logger.php`

Un archivo `.log` por canal. Simple a propósito: sin dependencias, funciona en cualquier hosting.

```php
<?php
declare(strict_types=1);

final class Logger
{
    public static function info(string $channel, string $message, array $context = []): void
    { self::write('INFO', $channel, $message, $context); }

    public static function warning(string $channel, string $message, array $context = []): void
    { self::write('WARNING', $channel, $message, $context); }

    public static function error(string $channel, string $message, array $context = []): void
    { self::write('ERROR', $channel, $message, $context); }

    private static function write(string $level, string $channel, string $message, array $context = []): void
    {
        $logsDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logsDir)) mkdir($logsDir, 0775, true);

        $line = sprintf('%s [%s] %s', date('c'), $level, $message);
        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $line .= ' ' . ($encoded === false ? '{}' : $encoded);
        }

        $filePath = $logsDir . '/' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $channel) . '.log';
        file_put_contents($filePath, $line . PHP_EOL, FILE_APPEND);
    }
}
```

**Canales usados:** `payments`, `webhook`, `reconcile`, `qr_submit`, `donors`, `admin_auth`,
`admin_users`, `admin_qr`, `admin_cash`, `admin_export`, `auditoria`.

> ⚠️ **Nunca loguees el `MP_ACCESS_TOKEN` ni datos completos de tarjeta.** Loguea IDs, estados y
> montos. Y protege `api/logs/` con `.htaccess` (Sección 16).

---

## 7. Canal 1 — Mercado Pago (Checkout Pro)

### 7.1 Reglas duras aprendidas en producción

Estas reglas son el resultado de depurar el sistema real. **Respétalas o vas a perder pagos.**

| # | Regla | Por qué |
|---|---|---|
| 1 | `external_reference` = `id` de la donación, **siempre string numérico** | Es la única forma de recuperar un pago si el webhook falla |
| 2 | Duplica el dato en `metadata.donation_id` | En algunos flujos MP no propaga `external_reference` al payment |
| 3 | `auto_return: 'approved'` **solo si `back_urls.success` es HTTPS** | Con HTTP, MP rechaza la creación de la preferencia con error 400 |
| 4 | El webhook puede llegar como `merchant_order` **antes** de que exista el payment | Hay que reconsultar la orden tras ~800 ms y, si sigue vacía, responder OK e ignorar |
| 5 | El webhook responde **500 en error real** | MP reintenta ante 5xx. Si respondes 200 tras un fallo, pierdes el pago para siempre |
| 6 | Nunca confíes en el `status` que llega en el body del webhook | Consulta siempre `GET /v1/payments/{id}` con tu access token |
| 7 | Con credenciales `APP_USR-` usa `init_point`; con `TEST-` clásicas, `sandbox_init_point` | Mezclarlos produce "Algo salió mal" en el checkout |
| 8 | En sandbox **no envíes el email real del donante** como `payer.email` | MP rechaza pagos cuando el pagador no es un usuario de prueba |
| 9 | La preferencia se crea **dentro de una transacción SQL** junto al INSERT | Si MP falla, no queda una donación huérfana en la BD |
| 10 | Guarda `mp_preference_id` inmediatamente | Es el plan B para encontrar el pago vía `merchant_orders/search` |

### 7.2 `api/donation/create.php`

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/utils/Response.php';
require_once dirname(__DIR__) . '/utils/Validator.php';
require_once dirname(__DIR__) . '/utils/Logger.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/mercadopago.php';

use MercadoPago\Exceptions\MPApiException;

Response::handleOptions();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Response::error('Método no permitido.', 405);
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    Response::error('JSON inválido.', 400);
}

$minOnline  = (float) app_env('DONATION_MIN_ONLINE', 50);
$validation = Validator::validateDonationPayload($payload, $minOnline);

if ($validation['valid'] !== true) {
    Response::error($validation['errors'][0] ?? 'Datos inválidos.', 422, ['errors' => $validation['errors']]);
}

$data = $validation['data'];
$ip   = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

$pdo = app_db();
app_db_ensure_schema($pdo);

try {
    $pdo->beginTransaction();

    // ── 1. Donación pendiente en BD ──────────────────────────────────────────
    $insert = $pdo->prepare(
        'INSERT INTO donaciones
         (nombre, documento, correo, telefono, tipo_aportante, categoria,
          monto_referencial, moneda, canal_pago, proveedor_pago,
          estado, visible_publico, acepta_terminos, ip_origen)
         VALUES
         (:nombre, :documento, :correo, :telefono, :tipo_aportante, :categoria,
          :monto_referencial, :moneda, :canal_pago, :proveedor_pago,
          :estado, :visible_publico, :acepta_terminos, :ip_origen)'
    );

    $insert->execute([
        ':nombre'            => $data['nombre'],
        ':documento'         => $data['documento'],
        ':correo'            => $data['correo'],
        ':telefono'          => trim((string) ($payload['telefono'] ?? '')) ?: null,
        ':tipo_aportante'    => in_array(($payload['tipo_aportante'] ?? 'persona'), ['persona', 'empresa'], true)
                                ? $payload['tipo_aportante'] : 'persona',
        ':categoria'         => $data['categoria'],
        ':monto_referencial' => $data['monto'],
        ':moneda'            => $data['moneda'],
        ':canal_pago'        => 'mercadopago',
        ':proveedor_pago'    => 'mercadopago',
        ':estado'            => 'pendiente',
        ':visible_publico'   => $data['visible_publico'] ? 1 : 0,
        ':acepta_terminos'   => $data['acepta_terminos'] ? 1 : 0,
        ':ip_origen'         => $ip !== '' ? $ip : null,
    ]);

    $donationId = (int) $pdo->lastInsertId();

    // ── 2. Datos del pagador (distinto en sandbox y en producción) ───────────
    $appEnv = strtolower((string) app_env('APP_ENV', 'sandbox'));
    $payer  = [];

    if ($appEnv !== 'sandbox') {
        $payer['name']  = $data['nombre'];
        $payer['email'] = $data['correo'];

        if (Validator::guessDocumentType((string) $data['documento']) === 'DNI') {
            $payer['identification'] = ['type' => 'DNI', 'number' => $data['documento']];
        }
    } else {
        // En sandbox SOLO se envía payer si es una cuenta de prueba, o si se fuerza por .env.
        $forceSandboxPayer = filter_var((string) app_env('MP_SANDBOX_FORCE_PAYER_TEST', 'false'), FILTER_VALIDATE_BOOLEAN);

        if ($forceSandboxPayer) {
            $configuredEmail = trim((string) app_env('MP_SANDBOX_PAYER_EMAIL', ''));
            if ($configuredEmail !== '') {
                $payer['email'] = $configuredEmail;
                $payer['name']  = (string) app_env('MP_SANDBOX_PAYER_NAME', 'APRO');

                $docType   = strtoupper(trim((string) app_env('MP_SANDBOX_PAYER_DOC_TYPE', '')));
                $docNumber = trim((string) app_env('MP_SANDBOX_PAYER_DOC_NUMBER', ''));

                if ($docType !== '' && $docNumber !== '') {
                    if ($docType === 'DNI') {
                        $docNumber = (string) preg_replace('/\D+/', '', $docNumber);
                    }
                    if ($docNumber !== '') {
                        $payer['identification'] = ['type' => $docType, 'number' => $docNumber];
                    }
                }
            }
        } elseif (stripos($data['correo'], '@testuser.com') !== false) {
            $payer['email'] = $data['correo'];
            $payer['name']  = $data['nombre'];
        }
    }

    // ── 3. URLs de retorno ───────────────────────────────────────────────────
    $successUrl = app_normalize_back_url((string) app_env('MP_SUCCESS_URL', ''), 'exitosa');
    $failureUrl = app_normalize_back_url((string) app_env('MP_FAILURE_URL', ''), 'fallida');
    $pendingUrl = app_normalize_back_url((string) app_env('MP_PENDING_URL', ''), 'pendiente');

    // ── 4. Preferencia ───────────────────────────────────────────────────────
    $preferencePayload = [
        'items' => [[
            'id'          => 'donation-' . $donationId,
            'title'       => 'Donación ' . (string) app_env('ORG_NAME', 'Campaña solidaria'),
            'quantity'    => 1,
            'currency_id' => $data['moneda'],
            'unit_price'  => (float) $data['monto'],
        ]],
        'back_urls' => [
            'success' => $successUrl,
            'failure' => $failureUrl,
            'pending' => $pendingUrl,
        ],
        'notification_url'   => (string) app_env('MP_WEBHOOK_URL', ''),
        'external_reference' => (string) $donationId,       // ⚠️ LLAVE MAESTRA
        'metadata' => [
            'donation_id'     => (string) $donationId,      // ⚠️ respaldo de la llave
            'categoria'       => $data['categoria'],
            'visible_publico' => $data['visible_publico'] ? 1 : 0,
        ],
    ];

    // MP exige success HTTPS para auto_return.
    if (preg_match('/^https:\/\//i', $successUrl) === 1) {
        $preferencePayload['auto_return'] = 'approved';
    }

    if ($payer !== []) {
        $preferencePayload['payer'] = $payer;
    }

    $preference   = app_mp_preference_client()->create($preferencePayload);
    $preferenceId = (string) ($preference->id ?? '');

    // ── 5. ¿init_point o sandbox_init_point? ─────────────────────────────────
    $publicKey   = strtoupper((string) app_env('MP_PUBLIC_KEY', ''));
    $accessToken = strtoupper((string) app_env('MP_ACCESS_TOKEN', ''));
    $usingAppCredentials = str_starts_with($publicKey, 'APP_USR-') || str_starts_with($accessToken, 'APP_USR-');

    $defaultUseSandboxPoint = $appEnv === 'sandbox' && !$usingAppCredentials;
    $useSandboxInitPoint = filter_var(
        (string) app_env('MP_USE_SANDBOX_INIT_POINT', $defaultUseSandboxPoint ? 'true' : 'false'),
        FILTER_VALIDATE_BOOLEAN
    );

    $initPoint = $useSandboxInitPoint
        ? (string) ($preference->sandbox_init_point ?? $preference->init_point ?? '')
        : (string) ($preference->init_point ?? $preference->sandbox_init_point ?? '');

    if ($preferenceId === '' || $initPoint === '') {
        throw new RuntimeException('Mercado Pago no devolvió preference_id/init_point');
    }

    $pdo->prepare('UPDATE donaciones SET mp_preference_id = :pref WHERE id = :id')
        ->execute([':pref' => $preferenceId, ':id' => $donationId]);

    $pdo->commit();

    Response::success([
        'init_point'           => $initPoint,
        'preference_id'        => $preferenceId,
        'donation_id'          => $donationId,
        'sandbox_mode'         => $appEnv === 'sandbox',
        'checkout_point_used'  => $useSandboxInitPoint ? 'sandbox_init_point' : 'init_point',
    ]);

} catch (MPApiException $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    $statusCode = null; $responseContent = null;
    if (method_exists($exception, 'getApiResponse')) {
        $apiResponse = $exception->getApiResponse();
        if ($apiResponse !== null && method_exists($apiResponse, 'getStatusCode')) $statusCode = $apiResponse->getStatusCode();
        if ($apiResponse !== null && method_exists($apiResponse, 'getContent'))    $responseContent = $apiResponse->getContent();
    }

    Logger::error('payments', 'Error de API Mercado Pago al crear preferencia', [
        'error' => $exception->getMessage(), 'status_code' => $statusCode, 'response' => $responseContent,
    ]);

    Response::error('No se pudo iniciar el pago. Intenta nuevamente.', 502);

} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    Logger::error('payments', 'Error interno al crear donación', ['error' => $exception->getMessage()]);
    Response::error('No se pudo procesar la donación en este momento.', 500);
}

/**
 * Normaliza una back_url: garantiza que apunte a la página de donaciones
 * y que lleve ?donacion=<estado> para que el front sepa qué mostrar.
 */
function app_normalize_back_url(string $url, string $estado): string
{
    $trimmed = trim($url);
    if ($trimmed === '') {
        $appUrl = rtrim((string) app_env('APP_URL', ''), '/');
        if ($appUrl === '') return '';
        $trimmed = $appUrl . '/donaciones.html';
    }

    $parts = parse_url($trimmed);
    if ($parts === false) return $trimmed;

    $query = [];
    if (isset($parts['query']) && $parts['query'] !== '') parse_str($parts['query'], $query);
    $query['donacion'] = $estado;

    $path = (string) ($parts['path'] ?? '');
    if ($path === '' || $path === '/' || strtolower($path) === '/index.html') {
        $path = '/donaciones.html';
    }

    $out = '';
    if (isset($parts['scheme'])) $out .= $parts['scheme'] . '://';
    if (isset($parts['host']))   $out .= $parts['host'];
    if (isset($parts['port']))   $out .= ':' . $parts['port'];
    $out .= $path;

    $qs = http_build_query($query);
    if ($qs !== '') $out .= '?' . $qs;
    if (isset($parts['fragment']) && $parts['fragment'] !== '') $out .= '#' . $parts['fragment'];

    return $out;
}
```

**Contrato de `POST /api/donation/create.php`**

Request:
```json
{
  "nombre": "María Pérez",
  "documento": "44556677",
  "correo": "maria@ejemplo.com",
  "telefono": "+51 999 888 777",
  "categoria": "Educación",
  "monto": 150,
  "moneda": "PEN",
  "tipo_aportante": "persona",
  "visible_publico": true,
  "acepta_terminos": true
}
```

Response 200:
```json
{
  "success": true,
  "init_point": "https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=...",
  "preference_id": "123456789-abcd-...",
  "donation_id": 481,
  "sandbox_mode": false,
  "checkout_point_used": "init_point"
}
```

Errores: `400` JSON inválido · `405` método · `422` validación (`errors[]`) · `502` MP caído ·
`500` interno.

### 7.3 `api/donation/webhook.php`

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/utils/Response.php';
require_once dirname(__DIR__) . '/utils/Logger.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/mercadopago.php';

use MercadoPago\Exceptions\MPApiException;

Response::handleOptions();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    Response::error('Método no permitido.', 405);
}

// MP manda el evento de formas distintas según la versión/configuración.
$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) $payload = [];

$eventType    = (string) ($payload['type'] ?? ($_GET['type'] ?? ($_GET['topic'] ?? '')));
$paymentIdRaw = $payload['data']['id'] ?? ($_GET['data_id'] ?? ($_GET['id'] ?? null));
if ($paymentIdRaw === null && isset($_GET['data.id'])) $paymentIdRaw = $_GET['data.id'];

$eventTypeNormalized = strtolower($eventType);
$paymentId = is_numeric((string) $paymentIdRaw) ? (int) $paymentIdRaw : 0;

$pdo = app_db();
$webhookLogId = 0;

// ── Siempre se registra el payload crudo, pase lo que pase ───────────────────
try {
    $insertLog = $pdo->prepare(
        'INSERT INTO webhook_logs (payload, evento, status, procesado) VALUES (:p, :e, :s, :pr)'
    );
    $insertLog->execute([
        ':p'  => $rawBody !== '' ? $rawBody : json_encode($payload),
        ':e'  => $eventType,
        ':s'  => 'received',
        ':pr' => 0,
    ]);
    $webhookLogId = (int) $pdo->lastInsertId();
} catch (Throwable $e) {
    Logger::warning('webhook', 'No se pudo registrar webhook_logs', ['error' => $e->getMessage()]);
}

// Eventos que no nos interesan → 200 para que MP deje de reintentar.
if (!in_array($eventTypeNormalized, ['payment', 'merchant_order'], true) || $paymentId <= 0) {
    app_update_webhook_log($pdo, $webhookLogId, 'ignored', 1);
    Response::success(['message' => 'Webhook recibido. Evento ignorado.']);
}

// ── merchant_order → resolver el payment asociado ────────────────────────────
if ($eventTypeNormalized === 'merchant_order') {
    try {
        $client = app_mp_merchant_order_client();
        $order  = $client->get($paymentId);

        $resolved = app_extract_payment_id_from_order($order);

        if ($resolved <= 0) {
            usleep(800000);                       // MP a veces aún no asoció el pago
            $order = $client->get($paymentId);
            $resolved = app_extract_payment_id_from_order($order);
        }

        if ($resolved <= 0) {
            app_update_webhook_log($pdo, $webhookLogId, 'order_without_payment', 1);
            Logger::info('webhook', 'merchant_order sin payment asociado', [
                'merchant_order_id'  => (int) $paymentIdRaw,
                'external_reference' => isset($order->external_reference) ? (string) $order->external_reference : '',
            ]);
            Response::success(['message' => 'Webhook merchant_order sin payment asociado.']);
        }

        $paymentId = $resolved;

    } catch (MPApiException $e) {
        app_update_webhook_log($pdo, $webhookLogId, 'mp_error', 0);
        Logger::error('webhook', 'Error API MP resolviendo merchant_order', ['id' => (int) $paymentIdRaw, 'error' => $e->getMessage()]);
        Response::error('Error al procesar webhook merchant_order.', 500);
    } catch (Throwable $e) {
        app_update_webhook_log($pdo, $webhookLogId, 'error', 0);
        Logger::error('webhook', 'Error interno resolviendo merchant_order', ['id' => (int) $paymentIdRaw, 'error' => $e->getMessage()]);
        Response::error('Error interno.', 500);
    }
}

// ── payment → consultar la fuente de verdad y actualizar ─────────────────────
try {
    $payment = app_mp_payment_client()->get($paymentId);

    $status       = (string) ($payment->status ?? 'pending');
    $mappedStatus = app_map_donation_status($status);
    $amount       = isset($payment->transaction_amount) ? (float) $payment->transaction_amount : null;

    // external_reference con respaldo en metadata
    $externalReference = (string) ($payment->external_reference ?? '');
    if ($externalReference === '' && isset($payment->metadata) && is_object($payment->metadata) && isset($payment->metadata->donation_id)) {
        $externalReference = (string) $payment->metadata->donation_id;
    }

    $donationId = ctype_digit($externalReference) ? (int) $externalReference : 0;

    if ($donationId > 0) {
        $update = $pdo->prepare(
            'UPDATE donaciones
             SET estado = :estado, mp_payment_id = :pid, monto_real = :monto, updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute([
            ':estado' => $mappedStatus,
            ':pid'    => (string) $paymentId,
            ':monto'  => $amount,
            ':id'     => $donationId,
        ]);

        if ($update->rowCount() === 0) {
            Logger::warning('webhook', 'No se encontró donación para external_reference', [
                'payment_id' => $paymentId, 'external_reference' => $externalReference,
            ]);
        }
    } else {
        Logger::warning('webhook', 'Webhook payment sin external_reference numérico', [
            'payment_id' => $paymentId, 'external_reference' => $externalReference,
        ]);
    }

    app_update_webhook_log($pdo, $webhookLogId, $mappedStatus, 1);
    Response::success(['message' => 'Webhook procesado.']);

} catch (MPApiException $e) {
    app_update_webhook_log($pdo, $webhookLogId, 'mp_error', 0);
    Logger::error('webhook', 'Error API MP en webhook', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
    Response::error('Error al procesar webhook.', 500);   // ⚠️ 500 a propósito: MP reintenta
} catch (Throwable $e) {
    app_update_webhook_log($pdo, $webhookLogId, 'error', 0);
    Logger::error('webhook', 'Error interno al procesar webhook', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
    Response::error('Error interno.', 500);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function app_map_donation_status(string $paymentStatus): string
{
    $s = strtolower($paymentStatus);
    if ($s === 'approved') return 'aprobado';
    if (in_array($s, ['rejected', 'cancelled', 'charged_back'], true)) return 'rechazado';
    return 'en_proceso';
}

/** De una merchant_order extrae el payment aprobado; si no hay, el primero válido. */
function app_extract_payment_id_from_order(object $order): int
{
    if (!isset($order->payments) || !is_array($order->payments)) return 0;

    $fallbackId = 0;
    foreach ($order->payments as $payment) {
        if (!is_object($payment)) continue;

        $candidateId = isset($payment->id) && is_numeric((string) $payment->id) ? (int) $payment->id : 0;
        if ($candidateId <= 0) continue;

        if (strtolower((string) ($payment->status ?? '')) === 'approved') return $candidateId;
        if ($fallbackId === 0) $fallbackId = $candidateId;
    }

    return $fallbackId;
}

function app_update_webhook_log(PDO $pdo, int $id, string $status, int $processed): void
{
    if ($id <= 0) return;
    try {
        $pdo->prepare('UPDATE webhook_logs SET status = :s, procesado = :p WHERE id = :id')
            ->execute([':s' => $status, ':p' => $processed, ':id' => $id]);
    } catch (Throwable $e) {
        Logger::warning('webhook', 'No se pudo actualizar webhook_logs', ['id' => $id, 'error' => $e->getMessage()]);
    }
}
```

**Configuración del webhook en el panel de Mercado Pago**

1. https://www.mercadopago.com.pe/developers → *Tus integraciones* → tu aplicación → **Webhooks**.
2. URL de producción: `https://midominio.org/api/donation/webhook.php`
3. Eventos: **Pagos** (`payment`). Opcionalmente **Órdenes comerciales** (`merchant_order`).
4. Prueba con el botón "Simular notificación" del panel y verifica la fila en `webhook_logs`.

> 💡 **Endurecimiento opcional (recomendado si se maneja mucho dinero):** MP firma cada webhook con
> la cabecera `x-signature` (HMAC-SHA256 sobre `id`, `x-request-id` y `ts`, con la clave secreta del
> webhook). El sistema original no la valida porque siempre reconsulta el pago con el access token
> propio —lo que ya impide falsificaciones—, pero validar la firma permite rechazar basura antes de
> gastar una llamada a la API.

### 7.4 `api/donation/reconcile.php` — la segunda red de seguridad

Se llama desde el navegador del donante al volver del checkout. Resuelve la donación por
cualquiera de las tres llaves disponibles y consulta el estado real en MP. **Es idempotente:**
se puede llamar mil veces sin efectos adversos.

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/utils/Response.php';
require_once dirname(__DIR__) . '/utils/Logger.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/mercadopago.php';

use MercadoPago\Net\MPSearchRequest;

Response::handleOptions();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    Response::error('Método no permitido.', 405);
}

$input = $method === 'POST'
    ? (json_decode(file_get_contents('php://input') ?: '', true) ?: null)
    : $_GET;

if (!is_array($input)) Response::error('JSON inválido.', 400);

$donationId   = app_parse_positive_int($input['donation_id'] ?? ($input['id'] ?? null));
$preferenceId = trim((string) ($input['preference_id'] ?? ''));
$paymentId    = app_parse_positive_int(
    $input['payment_id'] ?? ($input['collection_id'] ?? null) ?? ($input['data_id'] ?? null)
);

if ($donationId <= 0 && $preferenceId === '' && $paymentId <= 0) {
    Response::error('Debes enviar donation_id, preference_id o payment_id.', 422);
}

try {
    $pdo = app_db();
    app_db_ensure_schema($pdo);

    // ── Localizar la donación por cualquiera de las llaves ───────────────────
    $donation = null;

    if ($donationId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM donaciones WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $donationId]);
        $donation = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($donation === null && $preferenceId !== '') {
        $stmt = $pdo->prepare('SELECT * FROM donaciones WHERE mp_preference_id = :pref LIMIT 1');
        $stmt->execute([':pref' => $preferenceId]);
        $donation = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($donation === null && $paymentId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM donaciones WHERE mp_payment_id = :pid LIMIT 1');
        $stmt->execute([':pid' => (string) $paymentId]);
        $donation = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Último recurso: preguntarle a MP de qué donación es este pago.
    if ($donation === null && $paymentId > 0) {
        $payment = app_mp_payment_client()->get($paymentId);
        $externalRef = trim((string) ($payment->external_reference ?? ''));

        if ($externalRef !== '' && ctype_digit($externalRef)) {
            $stmt = $pdo->prepare('SELECT * FROM donaciones WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int) $externalRef]);
            $donation = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    if ($donation === null) Response::error('No se encontró la donación a reconciliar.', 404);

    $donationId = (int) ($donation['id'] ?? 0);
    if ($preferenceId === '') $preferenceId = trim((string) ($donation['mp_preference_id'] ?? ''));
    if ($paymentId <= 0 && ctype_digit((string) ($donation['mp_payment_id'] ?? ''))) {
        $paymentId = (int) $donation['mp_payment_id'];
    }

    // ── Sin payment_id: buscarlo por preference_id en merchant_orders ────────
    if ($paymentId <= 0 && $preferenceId !== '') {
        $search = app_mp_merchant_order_client()->search(
            new MPSearchRequest(20, 0, [
                'preference_id' => $preferenceId,
                'sort'          => 'date_created',
                'criteria'      => 'desc',
            ])
        );

        $orders = is_array($search->elements ?? null) ? $search->elements : [];
        foreach ($orders as $order) {
            if (!is_object($order) || !isset($order->payments) || !is_array($order->payments)) continue;

            $fallbackPaymentId = 0;
            foreach ($order->payments as $orderPayment) {
                if (!is_object($orderPayment)) continue;
                $candidate = isset($orderPayment->id) && is_numeric((string) $orderPayment->id) ? (int) $orderPayment->id : 0;
                if ($candidate <= 0) continue;

                if (strtolower((string) ($orderPayment->status ?? '')) === 'approved') {
                    $paymentId = $candidate;
                    break 2;
                }
                if ($fallbackPaymentId === 0) $fallbackPaymentId = $candidate;
            }

            if ($fallbackPaymentId > 0) $paymentId = $fallbackPaymentId;
        }
    }

    // Aún no hay pago: no es un error, el usuario puede haber abandonado.
    if ($paymentId <= 0) {
        Response::success([
            'reconciled'  => false,
            'status'      => (string) ($donation['estado'] ?? 'pendiente'),
            'message'     => 'Aún no existe un pago asociado en Mercado Pago.',
            'donation_id' => $donationId,
            'payment_id'  => null,
        ]);
    }

    // ── Estado real y actualización ─────────────────────────────────────────
    $payment      = app_mp_payment_client()->get($paymentId);
    $mpStatus     = strtolower((string) ($payment->status ?? 'pending'));
    $mappedStatus = app_map_donation_status($mpStatus);
    $amount       = isset($payment->transaction_amount) ? (float) $payment->transaction_amount : null;

    $pdo->prepare(
        'UPDATE donaciones
         SET estado = :estado, mp_payment_id = :pid, monto_real = :monto, updated_at = NOW()
         WHERE id = :id'
    )->execute([
        ':estado' => $mappedStatus,
        ':pid'    => (string) $paymentId,
        ':monto'  => $amount,
        ':id'     => $donationId,
    ]);

    Response::success([
        'reconciled'  => true,
        'status'      => $mappedStatus,
        'mp_status'   => $mpStatus,
        'donation_id' => $donationId,
        'payment_id'  => (string) $paymentId,
        'monto_real'  => $amount,
        'message'     => 'Donación reconciliada correctamente.',
    ]);

} catch (Throwable $e) {
    Logger::error('reconcile', 'Error reconciliando donación', [
        'donation_id' => $donationId, 'preference_id' => $preferenceId,
        'payment_id'  => $paymentId,  'error' => $e->getMessage(),
    ]);
    Response::error('No se pudo reconciliar la donación.', 500);
}

function app_parse_positive_int($value): int
{
    if ($value === null) return 0;
    $raw = trim((string) $value);
    return ($raw === '' || !ctype_digit($raw)) ? 0 : (int) $raw;
}

function app_map_donation_status(string $paymentStatus): string
{
    $s = strtolower($paymentStatus);
    if ($s === 'approved') return 'aprobado';
    if (in_array($s, ['rejected', 'cancelled', 'charged_back'], true)) return 'rechazado';
    return 'en_proceso';
}
```

> ⚠️ **Nota de seguridad:** este endpoint es público (el navegador del donante lo llama). Solo
> permite *leer* el estado real desde MP y sincronizarlo; un atacante no puede forzar un estado
> falso porque el valor siempre viene de la API de Mercado Pago autenticada con tu token. Aun así,
> considera añadir un rate-limit por IP (p. ej. 30 llamadas/minuto) si la campaña es grande.

### 7.5 Credenciales: sandbox vs. producción

| | Sandbox | Producción |
|---|---|---|
| `APP_ENV` | `sandbox` | `production` |
| Credenciales | `TEST-...` | `APP_USR-...` |
| `MP_USE_SANDBOX_INIT_POINT` | `true` (credenciales TEST clásicas) | `false` |
| `payer` | no enviar, o usuario de prueba | datos reales del donante |
| Tarjetas | las de prueba de MP con titular `APRO` | reales |

**Cuentas de prueba:** panel de MP → *Cuentas de prueba* → crea una de **comprador** y una de
**vendedor**. Usa las credenciales del vendedor de prueba en `.env` y paga con la del comprador.

**Titulares de prueba** (determinan el resultado): `APRO` aprueba · `OTHE` rechaza por error general ·
`CONT` deja pendiente · `FUND` rechaza por fondos insuficientes.

---

## 8. Canal 2 — QR Yape/Plin con comprobante

### 8.1 Flujo

```
1. El usuario llena el mismo formulario y pulsa "Donar con Yape/Plin"
2. Pasa al PASO 2: ve el QR estático de la organización + monto exacto a transferir
3. Transfiere desde su app bancaria
4. Sube la captura (JPG/PNG/WEBP/PDF, ≤ 10 MB)
5. Modal de confirmación con resumen + miniatura de la captura
6. POST multipart a qr_submit.php
     ├── valida datos + archivo (MIME real, no la extensión)
     ├── guarda en uploads/proofs/YYYY/MM/ con nombre aleatorio
     └── INSERT estado='pendiente', canal_pago='qr_manual'
7. Modal de éxito: "Nuestro equipo validará tu aporte"
8. El admin lo revisa en el panel → aprueba (fija monto_real) o rechaza
```

### 8.2 `api/donation/qr_submit.php`

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/utils/Response.php';
require_once dirname(__DIR__) . '/utils/Validator.php';
require_once dirname(__DIR__) . '/utils/Logger.php';
require_once dirname(__DIR__) . '/config/database.php';

Response::handleOptions();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Response::error('Método no permitido.', 405);
}

// multipart/form-data, no JSON
$input = [
    'nombre'          => (string) ($_POST['nombre'] ?? ''),
    'documento'       => (string) ($_POST['documento'] ?? ''),
    'correo'          => (string) ($_POST['correo'] ?? ''),
    'categoria'       => (string) ($_POST['categoria'] ?? ''),
    'monto'           => (string) ($_POST['monto'] ?? ''),
    'moneda'          => (string) ($_POST['moneda'] ?? 'PEN'),
    'visible_publico' => ($_POST['visible_publico'] ?? '1') === '1',
    'acepta_terminos' => ($_POST['acepta_terminos'] ?? '0') === '1',
];

$minQr = (float) app_env('DONATION_MIN_QR', 10);
$validation = Validator::validateDonationPayload($input, $minQr);
if ($validation['valid'] !== true) {
    Response::error($validation['errors'][0] ?? 'Datos inválidos.', 422, ['errors' => $validation['errors']]);
}

// ── Validación del archivo ───────────────────────────────────────────────────
if (!isset($_FILES['proof_file']) || !is_array($_FILES['proof_file'])) {
    Response::error('Debes adjuntar un comprobante.', 422);
}

$proof = $_FILES['proof_file'];
if ((int) ($proof['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    Response::error('No se pudo subir el comprobante.', 422);
}

$tmpPath = (string) ($proof['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {     // ⚠️ obligatorio
    Response::error('Archivo de comprobante inválido.', 422);
}

$size = (int) ($proof['size'] ?? 0);
if ($size <= 0 || $size > 10 * 1024 * 1024) {
    Response::error('El comprobante debe pesar entre 1 byte y 10 MB.', 422);
}

// ⚠️ El MIME se determina leyendo el archivo, NUNCA por su extensión ni por $_FILES['type'].
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = $finfo ? (string) finfo_file($finfo, $tmpPath) : '';
if ($finfo) finfo_close($finfo);

$allowedMimes = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];

if (!isset($allowedMimes[$mime])) {
    Response::error('Formato no permitido. Usa JPG, PNG, WEBP o PDF.', 422);
}

// ── Guardado: nombre aleatorio, jamás el nombre original ─────────────────────
$ext         = $allowedMimes[$mime];
$relativeDir = 'uploads/proofs/' . date('Y') . '/' . date('m');
$absoluteDir = dirname(__DIR__) . '/' . $relativeDir;

if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
    Response::error('No se pudo preparar el directorio de comprobantes.', 500);
}

$fileName     = 'donacion_qr_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$relativePath = $relativeDir . '/' . $fileName;
$absolutePath = dirname(__DIR__) . '/' . $relativePath;

if (!move_uploaded_file($tmpPath, $absolutePath)) {
    Response::error('No se pudo guardar el comprobante.', 500);
}

// ── Registro ─────────────────────────────────────────────────────────────────
$data           = $validation['data'];
$telefono       = trim((string) ($_POST['telefono'] ?? ''));
$tipoAportante  = strtolower(trim((string) ($_POST['tipo_aportante'] ?? 'persona')));
$referenciaPago = trim((string) ($_POST['referencia_pago'] ?? ''));
$ip             = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if ($referenciaPago === '') {
    $referenciaPago = strtoupper($tipoAportante) . '-QR-' . date('YmdHis');
}

try {
    $pdo = app_db();
    app_db_ensure_schema($pdo);

    $insert = $pdo->prepare(
        'INSERT INTO donaciones
         (nombre, documento, correo, telefono, tipo_aportante, categoria,
          monto_referencial, moneda, canal_pago, proveedor_pago, referencia_pago,
          comprobante_path, comprobante_mime, estado, visible_publico, acepta_terminos, ip_origen)
         VALUES
         (:nombre, :documento, :correo, :telefono, :tipo_aportante, :categoria,
          :monto, :moneda, :canal_pago, :proveedor_pago, :referencia_pago,
          :comprobante_path, :comprobante_mime, :estado, :visible_publico, :acepta_terminos, :ip_origen)'
    );

    $insert->execute([
        ':nombre'           => $data['nombre'],
        ':documento'        => $data['documento'],
        ':correo'           => $data['correo'],
        ':telefono'         => $telefono !== '' ? $telefono : null,
        ':tipo_aportante'   => in_array($tipoAportante, ['persona', 'empresa'], true) ? $tipoAportante : 'persona',
        ':categoria'        => $data['categoria'],
        ':monto'            => $data['monto'],
        ':moneda'           => $data['moneda'],
        ':canal_pago'       => 'qr_manual',
        ':proveedor_pago'   => 'yape_qr',
        ':referencia_pago'  => $referenciaPago,
        ':comprobante_path' => $relativePath,
        ':comprobante_mime' => $mime,
        ':estado'           => 'pendiente',
        ':visible_publico'  => $data['visible_publico'] ? 1 : 0,
        ':acepta_terminos'  => $data['acepta_terminos'] ? 1 : 0,
        ':ip_origen'        => $ip !== '' ? $ip : null,
    ]);

    Response::success([
        'donation_id' => (int) $pdo->lastInsertId(),
        'status'      => 'pendiente',
        'proof_path'  => $relativePath,
        'message'     => 'Comprobante registrado correctamente.',
    ], 201);

} catch (Throwable $e) {
    if (is_file($absolutePath)) @unlink($absolutePath);   // no dejes archivos huérfanos
    Logger::error('qr_submit', 'Error registrando donación QR', ['error' => $e->getMessage()]);
    Response::error('No se pudo registrar la donación.', 500);
}
```

**Contrato de `POST /api/donation/qr_submit.php`** — `multipart/form-data`:

| Campo | Tipo | Obligatorio |
|---|---|---|
| `nombre`, `documento`, `correo`, `categoria`, `monto` | text | ✅ |
| `telefono`, `moneda`, `tipo_aportante`, `referencia_pago` | text | opcional |
| `visible_publico`, `acepta_terminos` | `"1"` / `"0"` | ✅ |
| `proof_file` | file (JPG/PNG/WEBP/PDF ≤ 10 MB) | ✅ |

Response 201: `{ "success": true, "donation_id": 482, "status": "pendiente", "proof_path": "uploads/proofs/2026/09/…", "message": "…" }`

### 8.3 Protección del directorio de comprobantes

Los comprobantes contienen **datos bancarios personales**. Dos opciones:

**Opción A — servir solo a admins autenticados (recomendada).**
`api/uploads/.htaccess`:

```apache
Require all denied
```

Y un endpoint que valide la sesión antes de entregar el archivo:

```php
<?php // api/admin/qr/proof.php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__) . '/auth.php';

Response::handleOptions();
app_admin_require_auth();

$path = (string) ($_GET['path'] ?? '');

// Solo rutas dentro de uploads/proofs, sin travesía de directorios.
if (!preg_match('#^uploads/proofs/\d{4}/\d{2}/[A-Za-z0-9._-]+$#', $path)) {
    Response::error('Ruta inválida.', 400);
}

$absolute = dirname(__DIR__, 2) . '/' . $path;
$real     = realpath($absolute);
$base     = realpath(dirname(__DIR__, 2) . '/uploads/proofs');

if ($real === false || $base === false || !str_starts_with($real, $base) || !is_file($real)) {
    Response::error('Comprobante no encontrado.', 404);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = $finfo ? (string) finfo_file($finfo, $real) : 'application/octet-stream';
if ($finfo) finfo_close($finfo);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($real));
header('Content-Disposition: inline; filename="' . basename($real) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($real);
```

**Opción B — carpeta pública pero sin ejecución** (lo que hace el proyecto original; más simple,
menos privado). Como mínimo, impide que se ejecute PHP ahí:

```apache
# api/uploads/.htaccess
Options -Indexes -ExecCGI
php_flag engine off
AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .phps
<FilesMatch "\.(?i:php|phtml|phar|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
```

> ⚠️ **Nunca** permitas subir SVG: puede contener JavaScript y provoca XSS al abrirlo.

---

## 9. Canal 3 — Efectivo / Tesorería manual

Para donaciones que llegan por fuera del sitio (efectivo, transferencia bancaria directa,
convenio con una empresa). Un admin las registra y **nacen aprobadas**, con
`monto_real = monto_referencial`.

### 9.1 `api/admin/cash/save.php` (crear y editar)

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/utils/Logger.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/categories.php';
require_once dirname(__DIR__) . '/auth.php';

Response::handleOptions();
app_admin_require_auth();                    // ⚠️ primera línea después de CORS

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Response::error('Método no permitido.', 405);
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) Response::error('JSON inválido.', 400);

$donationId    = app_parse_positive_int($payload['id'] ?? null);   // >0 = edición
$tipoAportante = strtolower(trim((string) ($payload['tipo_aportante'] ?? 'persona')));
if (!in_array($tipoAportante, ['persona', 'empresa'], true)) {
    Response::error('tipo_aportante inválido.', 422);
}

$nombre     = app_sanitize_text((string) ($payload['nombre'] ?? ''));
$documento  = preg_replace('/\s+/', '', strtoupper((string) ($payload['documento'] ?? '')));
$correo     = strtolower(trim((string) ($payload['correo'] ?? '')));
$telefono   = trim((string) preg_replace('/\s+/', ' ', (string) ($payload['telefono'] ?? '')));
$categoria  = app_resolve_category_label((string) ($payload['categoria'] ?? ''), '');
$moneda     = strtoupper(trim((string) ($payload['moneda'] ?? 'PEN')));
$referencia = trim((string) ($payload['referencia_pago'] ?? ''));
$visible    = filter_var($payload['visible_publico'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

$montoRaw = $payload['monto'] ?? null;
$monto = ($montoRaw !== null && $montoRaw !== '')
    ? (float) (is_string($montoRaw) ? str_replace(',', '.', $montoRaw) : $montoRaw)
    : null;

// ── Validación (más estricta que la pública: es contabilidad) ────────────────
$errors = [];
if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 200) $errors[] = 'El nombre debe tener entre 3 y 200 caracteres.';

if ($tipoAportante === 'persona') {
    if (!preg_match('/^\d{8}$/', (string) $documento))  $errors[] = 'Para persona, el DNI debe tener 8 dígitos.';
} else {
    if (!preg_match('/^\d{11}$/', (string) $documento)) $errors[] = 'Para empresa, el RUC debe tener 11 dígitos.';
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL))                       $errors[] = 'Correo electrónico inválido.';
if ($telefono === '' || !preg_match('/^[0-9+()\-\s]{6,25}$/', $telefono)) $errors[] = 'Teléfono inválido.';
if ($categoria === '')                                                 $errors[] = 'Selecciona una categoría válida.';
if ($monto === null || !is_finite($monto) || $monto <= 0)              $errors[] = 'El monto debe ser mayor a 0.';
if ($monto !== null && $monto > 1000000)                               $errors[] = 'El monto máximo permitido es 1,000,000.00';
if (!in_array($moneda, ['PEN', 'USD'], true))                          $errors[] = 'Moneda no soportada.';

if ($errors !== []) Response::error($errors[0], 422, ['errors' => $errors]);

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

try {
    $pdo = app_db();
    app_db_ensure_schema($pdo);

    if ($donationId > 0) {
        // ⚠️ Solo se pueden editar donaciones del canal efectivo.
        $find = $pdo->prepare("SELECT id FROM donaciones WHERE id = :id AND canal_pago = 'efectivo_manual' LIMIT 1");
        $find->execute([':id' => $donationId]);
        if ($find->fetch(PDO::FETCH_ASSOC) === false) {
            Response::error('Donación en efectivo no encontrada.', 404);
        }

        $pdo->prepare(
            'UPDATE donaciones SET
                nombre = :nombre, documento = :documento, correo = :correo, telefono = :telefono,
                tipo_aportante = :tipo, categoria = :categoria,
                monto_referencial = :monto, monto_real = :monto_real, moneda = :moneda,
                referencia_pago = :referencia, estado = :estado, visible_publico = :visible,
                updated_at = NOW()
             WHERE id = :id'
        )->execute([
            ':nombre' => $nombre, ':documento' => $documento, ':correo' => $correo, ':telefono' => $telefono,
            ':tipo' => $tipoAportante, ':categoria' => $categoria,
            ':monto' => $monto, ':monto_real' => $monto, ':moneda' => $moneda,
            ':referencia' => $referencia !== '' ? $referencia : null,
            ':estado' => 'aprobado', ':visible' => $visible ? 1 : 0, ':id' => $donationId,
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO donaciones
             (nombre, documento, correo, telefono, tipo_aportante, categoria,
              monto_referencial, monto_real, moneda, canal_pago, proveedor_pago,
              referencia_pago, estado, visible_publico, acepta_terminos, ip_origen)
             VALUES
             (:nombre, :documento, :correo, :telefono, :tipo, :categoria,
              :monto, :monto_real, :moneda, :canal, :proveedor,
              :referencia, :estado, :visible, :terminos, :ip)'
        )->execute([
            ':nombre' => $nombre, ':documento' => $documento, ':correo' => $correo, ':telefono' => $telefono,
            ':tipo' => $tipoAportante, ':categoria' => $categoria,
            ':monto' => $monto, ':monto_real' => $monto, ':moneda' => $moneda,
            ':canal' => 'efectivo_manual', ':proveedor' => 'efectivo',
            ':referencia' => $referencia !== '' ? $referencia : null,
            ':estado' => 'aprobado', ':visible' => $visible ? 1 : 0,
            ':terminos' => 1, ':ip' => $ip !== '' ? $ip : null,
        ]);

        $donationId = (int) $pdo->lastInsertId();
    }

    $select = $pdo->prepare(
        'SELECT id, nombre, documento, correo, telefono, tipo_aportante, categoria,
                monto_referencial, monto_real, moneda, estado, referencia_pago,
                visible_publico, created_at, updated_at
         FROM donaciones WHERE id = :id LIMIT 1'
    );
    $select->execute([':id' => $donationId]);

    Logger::info('admin_cash', 'Donación en efectivo guardada', ['donation_id' => $donationId, 'monto' => $monto]);

    Response::success([
        'message' => 'Donación en efectivo guardada correctamente.',
        'item'    => $select->fetch(PDO::FETCH_ASSOC),
    ]);

} catch (Throwable $e) {
    Logger::error('admin_cash', 'Error guardando donación en efectivo', ['donation_id' => $donationId, 'error' => $e->getMessage()]);
    Response::error('No se pudo guardar la donación en efectivo.', 500);
}

function app_parse_positive_int($value): int
{
    if ($value === null) return 0;
    $raw = trim((string) $value);
    return ($raw === '' || !ctype_digit($raw)) ? 0 : (int) $raw;
}

function app_sanitize_text(string $value): string
{
    $clean = trim($value);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return (string) strip_tags((string) $clean);
}
```

> 💡 **Mejora recomendada:** añade una columna `registrado_por INT NULL` y guarda ahí el
> `id` del admin (`app_admin_require_auth()['id']`). En un módulo de tesorería, saber *quién*
> registró cada monto vale oro en una auditoría.

### 9.2 `api/admin/cash/list.php`

Mismo patrón: `app_admin_require_auth()`, solo GET, filtra `canal_pago = 'efectivo_manual'`,
`ORDER BY created_at DESC LIMIT 500`, y devuelve:

```json
{
  "success": true,
  "total": 12,
  "categorias_catalogo": ["General", "Educación", "Salud", "Medio Ambiente"],
  "items": [
    {
      "id": 480, "nombre": "Empresa XYZ SAC", "documento": "20512345678",
      "tipo_aportante": "empresa", "correo": "…", "telefono": "…",
      "categoria": "Educación", "monto_referencial": 5000.0, "monto_real": 5000.0,
      "moneda": "PEN", "estado": "aprobado", "visible_publico": true,
      "referencia_pago": "Depósito BCP 0012", "created_at": "…", "updated_at": "…"
    }
  ]
}
```

El campo `tipo_aportante` se deriva del documento si no está guardado
(`≥ 11 dígitos → empresa`, si no `persona`).

---

## 10. API pública de datos (dashboard)

`api/donors/list.php` — **el único endpoint público de lectura**. Alimenta la barra de progreso,
el ranking, el feed y el ticker. Se llama cada 30 s desde el navegador.

### 10.1 Las tres reglas de esta API

1. **Nunca expone PII de donantes anónimos.** Si `visible_publico = 0`, el nombre se reduce a
   iniciales (`"Donante Anónimo (M.P.)"`).
2. **Agrega en el servidor, no en el cliente.** El navegador recibe totales ya calculados.
3. **Redistribuye el comodín.** El dinero de la categoría `General` se reparte de forma pareja
   (en céntimos, sin perder ni un centavo por redondeo) entre las categorías activas, para que
   el ranking no tenga un bucket gigante llamado "General".

### 10.2 Implementación

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/utils/Response.php';
require_once dirname(__DIR__) . '/utils/Logger.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/config/categories.php';

Response::handleOptions();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Response::error('Método no permitido.', 405);
}

/** "María Elena Pérez" → "MEP" (máx. 3 iniciales) */
function app_anonymous_initials(string $name): string
{
    $normalized = trim((string) preg_replace('/\s+/', ' ', $name));
    if ($normalized === '') return '';

    $parts = preg_split('/[\s\-]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = ''; $count = 0;

    foreach ($parts as $part) {
        $letter = function_exists('mb_substr') ? (string) mb_substr($part, 0, 1, 'UTF-8') : (string) substr($part, 0, 1);
        if ($letter === '') continue;
        $initials .= mb_strtoupper($letter, 'UTF-8');
        if (++$count >= 3) break;
    }

    return $initials;
}

/** Nombre que se muestra públicamente, respetando el consentimiento. */
function app_public_donor_name(string $name, int $visiblePublico, string $paymentReference = ''): string
{
    $safeName = trim($name);
    if ($visiblePublico === 1) {
        return $safeName !== '' ? $safeName : 'Anónimo';
    }

    // Convenios corporativos cargados en lote que deben aparecer sin iniciales.
    $reference = strtoupper(trim($paymentReference));
    if (str_starts_with($reference, 'LOTE-ANON-')) {
        return 'Donación anónima';
    }

    $initials = app_anonymous_initials($safeName);
    return $initials !== '' ? sprintf('Donante Anónimo (%s)', $initials) : 'Donante Anónimo';
}

try {
    $pdo = app_db();
    app_db_ensure_schema($pdo);

    $includePending = filter_var((string) app_env('DASHBOARD_INCLUDE_PENDING', 'false'), FILTER_VALIDATE_BOOLEAN);
    $countedStates  = $includePending ? "('aprobado','pendiente','en_proceso')" : "('aprobado')";

    // ── Totales ─────────────────────────────────────────────────────────────
    $summary = $pdo->query(
        "SELECT COALESCE(SUM(COALESCE(monto_real, monto_referencial)), 0) AS total_recaudado,
                COUNT(*) AS total_donadores
         FROM donaciones WHERE estado IN {$countedStates}"
    )->fetch();

    $totalRecaudado = (float) ($summary['total_recaudado'] ?? 0);
    $totalDonadores = (int)   ($summary['total_donadores'] ?? 0);

    $goal = (float) app_env('DONATION_GOAL', 100000);
    if ($goal <= 0) $goal = 100000;
    $percentage = min(100, round(($totalRecaudado / $goal) * 100, 2));

    // ── Feed de donantes ────────────────────────────────────────────────────
    $donorsStmt = $pdo->query(
        "SELECT nombre, visible_publico, categoria, tipo_aportante,
                canal_pago, proveedor_pago, referencia_pago, estado,
                COALESCE(monto_real, monto_referencial) AS monto,
                created_at AS fecha
         FROM donaciones
         WHERE estado IN {$countedStates}
         ORDER BY created_at DESC
         LIMIT 1000"
    );

    $donadores = [];
    while ($row = $donorsStmt->fetch()) {
        $donadores[] = [
            'nombre' => app_public_donor_name(
                (string) ($row['nombre'] ?? ''),
                (int) ($row['visible_publico'] ?? 1),
                (string) ($row['referencia_pago'] ?? '')
            ),
            'es_anonimo'     => (int) ($row['visible_publico'] ?? 1) !== 1,
            'categoria'      => app_resolve_category_label((string) ($row['categoria'] ?? ''), 'General'),
            'monto'          => (float) ($row['monto'] ?? 0),
            'fecha'          => (string) ($row['fecha'] ?? date('Y-m-d H:i:s')),
            'estado'         => (string) ($row['estado'] ?? 'pendiente'),
            'canal_pago'     => (string) ($row['canal_pago'] ?? ''),
            'tipo_aportante' => (string) ($row['tipo_aportante'] ?? ''),
            // ⚠️ NO expongas documento, correo, teléfono ni IP en este endpoint.
        ];
    }

    // ── Agregado por categoría ──────────────────────────────────────────────
    $seed = [];
    foreach (app_categories_labels() as $label) {
        $seed[$label] = ['categoria' => $label, 'total' => 0.0, 'donadores' => 0, 'last_donation_at' => ''];
    }

    $groupStmt = $pdo->query(
        "SELECT COALESCE(NULLIF(TRIM(categoria), ''), 'General') AS categoria,
                COALESCE(SUM(COALESCE(monto_real, monto_referencial)), 0) AS total,
                COUNT(*) AS donadores,
                MAX(created_at) AS last_donation_at
         FROM donaciones
         WHERE estado IN {$countedStates}
         GROUP BY categoria"
    );

    while ($row = $groupStmt->fetch()) {
        $label = app_resolve_category_label((string) ($row['categoria'] ?? ''), 'General');
        if (!isset($seed[$label])) {
            $seed[$label] = ['categoria' => $label, 'total' => 0.0, 'donadores' => 0, 'last_donation_at' => ''];
        }
        $seed[$label]['total']            += (float) ($row['total'] ?? 0);
        $seed[$label]['donadores']        += (int)   ($row['donadores'] ?? 0);
        $seed[$label]['last_donation_at']  = (string) ($row['last_donation_at'] ?? '');
    }

    // ── Redistribución del comodín "General" ────────────────────────────────
    // Reparte el monto en CÉNTIMOS enteros para no perder centavos por redondeo.
    // Cada donante "General" cuenta como +1 donante en cada categoría activa
    // (decisión de producto: el aporte general apoya a todas las causas).
    $generalLabel  = app_resolve_category_label('General', 'General');
    $targetLabels  = array_values(array_filter(
        app_donatable_categories_labels(),
        static fn (string $label): bool => $label !== $generalLabel
    ));

    if (isset($seed[$generalLabel]) && $targetLabels !== []) {
        $generalTotal  = (float) ($seed[$generalLabel]['total'] ?? 0.0);
        $generalDonors = (int)   ($seed[$generalLabel]['donadores'] ?? 0);
        $generalLastAt = (string) ($seed[$generalLabel]['last_donation_at'] ?? '');

        if ($generalTotal > 0 || $generalDonors > 0) {
            $count      = count($targetLabels);
            $totalCents = (int) round($generalTotal * 100);
            $baseShare  = intdiv($totalCents, $count);
            $remainder  = $totalCents % $count;
            $generalTs  = strtotime($generalLastAt);

            foreach ($targetLabels as $index => $label) {
                if (!isset($seed[$label])) continue;

                $extraCent = $index < $remainder ? 1 : 0;          // reparte el resto, céntimo a céntimo
                $seed[$label]['total']     += ($baseShare + $extraCent) / 100;
                $seed[$label]['donadores'] += $generalDonors;

                if ($generalTs !== false) {
                    $currentTs = strtotime((string) ($seed[$label]['last_donation_at'] ?? ''));
                    if ($currentTs === false || $generalTs > $currentTs) {
                        $seed[$label]['last_donation_at'] = $generalLastAt;
                    }
                }
            }

            // Se vacía para no duplicar el dinero en el ranking.
            $seed[$generalLabel]['total']     = 0.0;
            $seed[$generalLabel]['donadores'] = 0;
        }
    }

    foreach ($seed as &$row) {
        $row['total']     = round((float) ($row['total'] ?? 0.0), 2);
        $row['donadores'] = (int) ($row['donadores'] ?? 0);
    }
    unset($row);

    $categorias = array_values($seed);
    usort($categorias, static function (array $a, array $b): int {
        if ((float) $a['total'] === (float) $b['total']) return (int) $b['donadores'] <=> (int) $a['donadores'];
        return (float) $b['total'] <=> (float) $a['total'];
    });

    $lookup = array_fill_keys($targetLabels, true);
    $activas = 0; $top = null;
    foreach ($categorias as $row) {
        $label = (string) ($row['categoria'] ?? '');
        if (isset($lookup[$label]) && (float) $row['total'] > 0) {
            $activas++;
            if ($top === null) $top = $label;
        }
    }

    Response::success([
        'total_recaudado'           => round($totalRecaudado, 2),
        'meta'                      => round($goal, 2),
        'porcentaje'                => $percentage,
        'total_donadores'           => $totalDonadores,
        'categorias_catalogo'       => app_donatable_categories_labels(),
        'categorias_no_disponibles' => array_values(array_map(
            static fn (string $key): string => app_categories_catalog()[$key],
            app_unavailable_category_keys()
        )),
        'categorias'                => $categorias,
        'categorias_activas'        => $activas,
        'categoria_top'             => $top,
        'donadores'                 => $donadores,
    ]);

} catch (Throwable $e) {
    Logger::error('donors', 'Error listando donantes', ['error' => $e->getMessage()]);
    Response::error('No se pudo obtener la lista de donantes.', 500);
}
```

### 10.3 Contrato de respuesta

```json
{
  "success": true,
  "total_recaudado": 48250.00,
  "meta": 100000.00,
  "porcentaje": 48.25,
  "total_donadores": 137,
  "categorias_catalogo": ["Educación", "Salud", "Medio Ambiente", "General"],
  "categorias_no_disponibles": ["Cultura"],
  "categorias": [
    { "categoria": "Educación", "total": 21300.50, "donadores": 62, "last_donation_at": "2026-09-14 19:02:11" }
  ],
  "categorias_activas": 3,
  "categoria_top": "Educación",
  "donadores": [
    { "nombre": "María Pérez", "es_anonimo": false, "categoria": "Educación",
      "monto": 150.0, "fecha": "2026-09-14 19:02:11", "estado": "aprobado",
      "canal_pago": "mercadopago", "tipo_aportante": "persona" }
  ]
}
```

> ⚠️ **Rendimiento.** Con > 10 000 donaciones, cachea esta respuesta 30–60 s en un archivo JSON o
> APCu. Las tres consultas son ligeras gracias a los índices, pero el endpoint recibe una llamada
> cada 30 s **por pestaña abierta**. Un caché de 30 s hace la carga constante sin importar el
> tráfico.

---

## 11. Autenticación de administradores

Sesiones PHP nativas con cookie `HttpOnly`. Sin JWT, sin tokens en `localStorage`: en un panel
administrativo servido desde el mismo dominio, la cookie de sesión es más segura y más simple.

### 11.1 `api/admin/auth.php` — el núcleo

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/utils/Response.php';

if (!function_exists('app_admin_start_session')) {
    function app_admin_start_session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        ini_set('session.use_strict_mode', '1');   // rechaza IDs de sesión inventados
        ini_set('session.use_only_cookies', '1');  // nunca el ID en la URL
        ini_set('session.cookie_httponly', '1');   // invisible para JavaScript

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,          // muere al cerrar el navegador
                'path'     => '/',
                'secure'   => $isHttps,   // solo HTTPS en producción
                'httponly' => true,
                'samesite' => 'Lax',      // protege contra CSRF en navegación cruzada
            ]);
        } else {
            session_set_cookie_params(0, '/; samesite=Lax', '', $isHttps, true);
        }

        session_name('APPADMINSESSID');   // ⚠️ único por organización
        session_start();
    }
}

if (!function_exists('app_admin_current_user')) {
    /** @return array<string, mixed>|null */
    function app_admin_current_user(): ?array
    {
        app_admin_start_session();

        if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            return null;
        }

        return [
            'id'       => (int)    ($_SESSION['admin_user_id'] ?? 0),
            'username' => (string) ($_SESSION['admin_username'] ?? ''),
            'role'     => (string) ($_SESSION['admin_role'] ?? 'editor'),
        ];
    }
}

if (!function_exists('app_admin_require_auth')) {
    /** Corta la ejecución con 401 si no hay sesión. @return array<string, mixed> */
    function app_admin_require_auth(): array
    {
        $user = app_admin_current_user();
        if ($user === null) Response::error('No autorizado.', 401);
        return $user;
    }
}

if (!function_exists('app_admin_require_superadmin')) {
    /** @return array<string, mixed> */
    function app_admin_require_superadmin(): array
    {
        $user = app_admin_require_auth();
        if (($user['role'] ?? '') !== 'superadmin') {
            Response::error('Permisos insuficientes.', 403);
        }
        return $user;
    }
}
```

**Regla de oro:** todo endpoint bajo `api/admin/` empieza así, sin excepciones:

```php
Response::handleOptions();
app_admin_require_auth();        // o app_admin_require_superadmin()
```

### 11.2 `api/admin/login.php`

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/Logger.php';

Response::handleOptions();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Response::error('Método no permitido.', 405);
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) Response::error('JSON inválido.', 400);

$username = trim((string) ($payload['username'] ?? ''));
$password = (string) ($payload['password'] ?? '');

if ($username === '' || $password === '') {
    Response::error('Usuario y contraseña son requeridos.', 400);
}

// Formato inválido → mismo mensaje genérico que credenciales malas (no filtres información).
if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
    Response::error('Credenciales inválidas.', 401);
}

try {
    $pdo = app_db();

    $stmt = $pdo->prepare('SELECT id, username, password_hash, role FROM admin_users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($user) || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
        sleep(1);                                   // freno básico a la fuerza bruta
        Logger::warning('admin_auth', 'Intento de login fallido', [
            'username' => $username, 'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
        Response::error('Credenciales inválidas.', 401);
    }

    app_admin_start_session();
    session_regenerate_id(true);                    // ⚠️ evita fijación de sesión

    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_user_id']   = (int)    ($user['id'] ?? 0);
    $_SESSION['admin_role']      = (string) ($user['role'] ?? 'editor');
    $_SESSION['admin_username']  = (string) ($user['username'] ?? '');

    $pdo->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = :id')->execute([':id' => (int) $user['id']]);

    // Migra el hash si el algoritmo por defecto cambió.
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT)) {
        $pdo->prepare('UPDATE admin_users SET password_hash = :h WHERE id = :id')
            ->execute([':h' => password_hash($password, PASSWORD_BCRYPT), ':id' => (int) $user['id']]);
    }

    Response::success([
        'message' => 'Login exitoso.',
        'user'    => ['username' => (string) $user['username'], 'role' => (string) $user['role']],
    ]);

} catch (Throwable $e) {
    Logger::error('admin_auth', 'Error durante login admin', ['error' => $e->getMessage()]);
    Response::error('No se pudo iniciar sesión.', 500);
}
```

> 💡 **Mejora recomendada:** bloqueo temporal tras N intentos fallidos. Añade una tabla
> `admin_login_attempts (ip, username, created_at)` y responde 429 si hay ≥ 5 fallos en 15 minutos
> desde la misma IP. El `sleep(1)` ayuda, pero no detiene un ataque distribuido.

### 11.3 `logout.php` y `check_auth.php`

```php
<?php // api/admin/logout.php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

Response::handleOptions();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Response::error('Método no permitido.', 405);
}

app_admin_start_session();
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'] ?? '/', $p['domain'] ?? '',
              (bool) ($p['secure'] ?? false), (bool) ($p['httponly'] ?? true));
}

session_destroy();
Response::success(['message' => 'Sesión cerrada exitosamente.']);
```

```php
<?php // api/admin/check_auth.php  → lo consume el guard del frontend
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

Response::handleOptions();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Response::error('Método no permitido.', 405);
}

$user = app_admin_current_user();
if ($user === null) {
    Response::json(['authenticated' => false, 'message' => 'No autorizado.'], 401);
}

Response::json([
    'authenticated' => true,
    'user' => [
        'id'       => (int) $user['id'],
        'username' => (string) $user['username'],
        'role'     => (string) $user['role'],
    ],
]);
```

### 11.4 `api/admin/users.php` — CRUD de administradores (solo superadmin)

Un archivo, cuatro métodos. Reglas de negocio que **debes conservar**:

| Regla | Motivo |
|---|---|
| `GET` nunca devuelve `password_hash` | Obvio, pero fácil de olvidar con un `SELECT *` |
| Username: `/^[a-zA-Z0-9._-]{3,50}$/` | Evita colisiones y caracteres raros |
| Contraseña: 8–72 caracteres | 72 es el límite real de bcrypt; más allá se trunca en silencio |
| No puedes quitarte a ti mismo el rol de superadmin | Evita quedarse sin acceso |
| No puedes eliminarte a ti mismo | Ídem |
| No se puede eliminar el **último** superadmin | Ídem |
| Hash siempre con `password_hash($pass, PASSWORD_BCRYPT)` | Nunca MD5/SHA |

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/Logger.php';

Response::handleOptions();
$actor  = app_admin_require_superadmin();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $pdo = app_db();

    if ($method === 'GET') {
        $stmt = $pdo->query('SELECT id, username, role, last_login, created_at FROM admin_users ORDER BY id ASC');
        Response::success(['usuarios' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'POST') {
        $payload  = app_parse_json_body();
        $username = app_validate_username((string) ($payload['username'] ?? ''));
        $password = app_validate_password((string) ($payload['password'] ?? ''));
        $role     = app_normalize_role((string) ($payload['role'] ?? 'editor'));

        $check = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u LIMIT 1');
        $check->execute([':u' => $username]);
        if ($check->fetch(PDO::FETCH_ASSOC)) Response::error('El nombre de usuario ya existe.', 409);

        $pdo->prepare('INSERT INTO admin_users (username, password_hash, role) VALUES (:u, :h, :r)')
            ->execute([':u' => $username, ':h' => password_hash($password, PASSWORD_BCRYPT), ':r' => $role]);

        Logger::info('admin_users', 'Usuario creado', ['por' => $actor['username'], 'nuevo' => $username]);
        Response::success(['message' => 'Usuario creado correctamente.'], 201);
    }

    if ($method === 'PUT') {
        $payload = app_parse_json_body();
        $userId  = (int) ($payload['id'] ?? 0);
        if ($userId <= 0) Response::error('ID de usuario requerido.', 400);

        $updates = [];
        $params  = [':id' => $userId];

        if (array_key_exists('username', $payload)) {
            $username = app_validate_username((string) $payload['username']);
            $check = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u AND id <> :id LIMIT 1');
            $check->execute([':u' => $username, ':id' => $userId]);
            if ($check->fetch(PDO::FETCH_ASSOC)) Response::error('El nombre de usuario ya existe.', 409);

            $updates[] = 'username = :username';
            $params[':username'] = $username;
        }

        if (array_key_exists('password', $payload) && trim((string) $payload['password']) !== '') {
            $updates[] = 'password_hash = :password_hash';
            $params[':password_hash'] = password_hash(app_validate_password((string) $payload['password']), PASSWORD_BCRYPT);
        }

        if (array_key_exists('role', $payload)) {
            $role = app_normalize_role((string) $payload['role']);
            if ($userId === (int) ($actor['id'] ?? 0) && $role !== 'superadmin') {
                Response::error('No puedes quitarte el rol de superadmin.', 403);
            }
            $updates[] = 'role = :role';
            $params[':role'] = $role;
        }

        if ($updates === []) Response::error('No hay datos para actualizar.', 400);

        $update = $pdo->prepare('UPDATE admin_users SET ' . implode(', ', $updates) . ' WHERE id = :id');
        $update->execute($params);

        if ($update->rowCount() === 0) Response::error('Usuario no encontrado o sin cambios.', 404);
        Response::success(['message' => 'Usuario actualizado con éxito.']);
    }

    if ($method === 'DELETE') {
        $payload = app_parse_json_body();
        $userId  = (int) ($payload['id'] ?? 0);
        if ($userId <= 0) Response::error('ID de usuario requerido.', 400);
        if ($userId === (int) ($actor['id'] ?? 0)) Response::error('No puedes eliminarte a ti mismo.', 403);

        $find = $pdo->prepare('SELECT id, role FROM admin_users WHERE id = :id LIMIT 1');
        $find->execute([':id' => $userId]);
        $target = $find->fetch(PDO::FETCH_ASSOC);
        if (!is_array($target)) Response::error('Usuario no encontrado.', 404);

        if (($target['role'] ?? '') === 'superadmin') {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'superadmin'")->fetchColumn();
            if ($count <= 1) Response::error('No puedes eliminar el último superadmin.', 409);
        }

        $pdo->prepare('DELETE FROM admin_users WHERE id = :id')->execute([':id' => $userId]);
        Logger::warning('admin_users', 'Usuario eliminado', ['por' => $actor['username'], 'id' => $userId]);
        Response::success(['message' => 'Usuario eliminado con éxito.']);
    }

    Response::error('Método no soportado.', 405);

} catch (Throwable $e) {
    Logger::error('admin_users', 'Error en gestión de usuarios', ['error' => $e->getMessage()]);
    Response::error('Error interno del servidor.', 500);
}

/** @return array<string, mixed> */
function app_parse_json_body(): array
{
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) Response::error('JSON inválido.', 400);
    return $payload;
}

function app_validate_username(string $username): string
{
    $clean = trim($username);
    if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $clean)) {
        Response::error('Username inválido. Usa 3-50 caracteres [a-zA-Z0-9._-].', 422);
    }
    return $clean;
}

function app_validate_password(string $password): string
{
    if (strlen($password) < 8 || strlen($password) > 72) {
        Response::error('La contraseña debe tener entre 8 y 72 caracteres.', 422);
    }
    return $password;
}

function app_normalize_role(string $role): string
{
    $clean = strtolower(trim($role));
    if (!in_array($clean, ['superadmin', 'editor'], true)) Response::error('Rol inválido.', 422);
    return $clean;
}
```

### 11.5 Crear el primer superadmin (CLI, nunca en el SQL)

`api/create_admin.php` — se ejecuta una vez desde la terminal y **se borra del servidor después**:

```php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // ⚠️ jamás por web

require_once __DIR__ . '/config/database.php';

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';
$role     = $argv[3] ?? 'superadmin';

if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username) || strlen($password) < 8) {
    fwrite(STDERR, "Uso: php create_admin.php <usuario> <contraseña(min 8)> [superadmin|editor]\n");
    exit(1);
}

$pdo = app_db();
$pdo->prepare(
    'INSERT INTO admin_users (username, password_hash, role) VALUES (:u, :h, :r)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role)'
)->execute([':u' => $username, ':h' => password_hash($password, PASSWORD_BCRYPT), ':r' => $role]);

echo "OK: usuario '$username' listo con rol '$role'." . PHP_EOL;
```

```bash
php api/create_admin.php admin "UnaClaveLargaYUnica2026" superadmin
rm api/create_admin.php     # borrar del servidor
```

### 11.6 Protección de las páginas HTML del panel

El HTML del panel **no debe servirse nunca sin sesión**. Wrapper PHP + redirección en `.htaccess`:

```php
<?php // panel_admin.php
declare(strict_types=1);
require_once __DIR__ . '/api/admin/auth.php';

app_admin_require_auth();               // 401 JSON si no hay sesión
readfile(__DIR__ . '/panel_admin.html');
```

```php
<?php // admin_usuarios.php
declare(strict_types=1);
require_once __DIR__ . '/api/admin/auth.php';

app_admin_require_superadmin();
readfile(__DIR__ . '/admin_usuarios.html');
```

```apache
# .htaccess de la raíz: nadie llega al .html directamente
RewriteRule ^panel_admin\.html$    panel_admin.php    [L,R=302,NC]
RewriteRule ^admin_usuarios\.html$ admin_usuarios.php [L,R=302,NC]
```

> 💡 **Mejor aún:** mueve los `.html` del panel a una carpeta `private/` fuera del *document root*
> y que el wrapper haga `readfile('../private/panel_admin.html')`. Así ni siquiera dependes del
> rewrite.

---

## 12. Panel administrativo

### 12.1 Secciones

```
┌──────────────────────────────────────────────────────────────────┐
│ TOPBAR   logo · Ver sitio público · Gestión de usuarios* · Salir │  *solo superadmin
├──────────────────────────────────────────────────────────────────┤
│ 1. Exportación ejecutiva de donantes      [Exportar Excel]       │
│ 2. Paquete de auditoría de fondos         [Descargar ZIP]        │
│ 3. Auditoría de pagos QR                                         │
│      filtro: pendientes | aprobados | rechazados                 │
│      tabla → clic en una fila abre el MODAL DE REVISIÓN:         │
│        · visor del comprobante con zoom 1x–4x                    │
│        · datos del donante + monto declarado                     │
│        · input "monto recibido real" (editable)                  │
│        · [Rechazar]  [Aprobar]                                   │
│ 4. Tesorería: aportes en efectivo                                │
│      formulario (persona/empresa) + tabla editable               │
│      confirmación modal antes de escribir en la BD               │
├──────────────────────────────────────────────────────────────────┤
│ Mensaje de estado (is-ok / is-error)                             │
└──────────────────────────────────────────────────────────────────┘
```

### 12.2 Guard de sesión en el HTML

Va en el `<head>`, antes que nada:

```html
<script>
  function resolveApiBase() {
    const { hostname, port } = window.location;
    if ((hostname === 'localhost' || hostname === '127.0.0.1') && port === '3000') {
      return 'http://' + hostname + '/mi_proyecto/api';   // dev server distinto de Apache
    }
    return 'api';
  }

  const API_BASE = resolveApiBase();

  fetch(API_BASE + '/admin/check_auth.php', { credentials: 'include' })
    .then(res => { if (!res.ok) { location.href = 'admin_login.html'; return null; } return res.json(); })
    .then(data => {
      if (!data) return;
      // El botón de usuarios solo se muestra a superadmin.
      if (data.authenticated && data.user.role === 'superadmin') {
        document.getElementById('btn-usuarios').style.display = 'flex';
      }
    })
    .catch(() => { location.href = 'admin_login.html'; });

  function logoutAdmin() {
    fetch(API_BASE + '/admin/logout.php', { method: 'POST', credentials: 'include' })
      .then(() => { location.href = 'admin_login.html'; });
  }
</script>
```

> ⚠️ Este guard es **cosmético**: solo oculta la UI. La seguridad real está en
> `app_admin_require_auth()` del servidor y en el wrapper PHP. Nunca dependas solo del JavaScript.

### 12.3 `credentials: 'include'` en **todas** las llamadas admin

Sin esa opción el navegador no manda la cookie de sesión y todo responde 401. Helper central:

```js
async function requestJson(url, options) {
  const config = options ? Object.assign({}, options) : {};
  if (!Object.prototype.hasOwnProperty.call(config, 'credentials')) {
    config.credentials = 'include';        // ⚠️ SIEMPRE
  }

  const response = await fetch(url, config);
  const text = await response.text();

  let data = null;
  try { data = text ? JSON.parse(text) : null; }
  catch (e) { throw new Error('Respuesta inválida del servidor.'); }

  if (!response.ok || !data || data.success !== true) {
    throw new Error((data && data.error) || 'Error de comunicación con el servidor.');
  }

  return data;
}
```

### 12.4 Cola de verificación QR

**`api/admin/qr/list.php`** — `GET ?state=pending|approved|rejected|all`

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/utils/Logger.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/categories.php';
require_once dirname(__DIR__, 2) . '/config/env.php';
require_once dirname(__DIR__) . '/auth.php';

Response::handleOptions();
app_admin_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') Response::error('Método no permitido.', 405);

$state = strtolower(trim((string) ($_GET['state'] ?? 'pending')));

try {
    $pdo = app_db();
    app_db_ensure_schema($pdo);

    $where = "canal_pago = 'qr_manual'";
    if     ($state === 'pending')  $where .= " AND estado IN ('pendiente', 'en_proceso')";
    elseif ($state === 'approved') $where .= " AND estado = 'aprobado'";
    elseif ($state === 'rejected') $where .= " AND estado = 'rechazado'";

    $stmt = $pdo->query(
        "SELECT id, nombre, documento, correo, telefono, tipo_aportante, categoria,
                monto_referencial, monto_real, moneda, estado, referencia_pago,
                comprobante_path, created_at, updated_at
         FROM donaciones
         WHERE {$where}
         ORDER BY created_at DESC
         LIMIT 300"
    );

    $items = [];
    while ($row = $stmt->fetch()) {
        $comprobantePath = (string) ($row['comprobante_path'] ?? '');

        $items[] = [
            'id'                => (int) ($row['id'] ?? 0),
            'nombre'            => (string) ($row['nombre'] ?? ''),
            'documento'         => (string) ($row['documento'] ?? ''),
            'tipo_aportante'    => (string) ($row['tipo_aportante'] ?? 'persona'),
            'correo'            => (string) ($row['correo'] ?? ''),
            'telefono'          => (string) ($row['telefono'] ?? ''),
            'categoria'         => app_resolve_category_label((string) ($row['categoria'] ?? ''), 'General'),
            'monto_referencial' => (float) ($row['monto_referencial'] ?? 0),
            'monto_real'        => $row['monto_real'] !== null ? (float) $row['monto_real'] : null,
            'moneda'            => (string) ($row['moneda'] ?? 'PEN'),
            'estado'            => (string) ($row['estado'] ?? 'pendiente'),
            'referencia_pago'   => (string) ($row['referencia_pago'] ?? ''),
            'comprobante_path'  => $comprobantePath,
            'comprobante_url'   => app_build_proof_url($comprobantePath),   // la carga el <img> del modal
            'created_at'        => (string) ($row['created_at'] ?? ''),
            'updated_at'        => (string) ($row['updated_at'] ?? ''),
        ];
    }

    Response::success(['state' => $state, 'total' => count($items), 'items' => $items]);

} catch (Throwable $e) {
    Logger::error('admin_qr', 'Error listando verificación QR', ['error' => $e->getMessage()]);
    Response::error('No se pudo listar la verificación QR.', 500);
}

function app_build_proof_url(string $relativePath): string
{
    $clean = ltrim(trim($relativePath), '/');
    if ($clean === '') return '';
    if (preg_match('/^https?:\/\//i', $clean) === 1) return $clean;

    // Si usas el endpoint protegido de la Sección 8.3:
    // return rtrim((string) app_env('APP_URL', ''), '/') . '/api/admin/qr/proof.php?path=' . rawurlencode($clean);

    if (str_starts_with($clean, 'uploads/')) $clean = 'api/' . $clean;

    $appUrl = rtrim((string) app_env('APP_URL', ''), '/');
    return $appUrl !== '' ? $appUrl . '/' . $clean : $clean;
}
```

**`api/admin/qr/verify.php`** — `POST { donation_id, estado, monto_real? }`

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/utils/Logger.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__) . '/auth.php';

Response::handleOptions();
$actor = app_admin_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') Response::error('Método no permitido.', 405);

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) Response::error('JSON inválido.', 400);

$donationId = isset($payload['donation_id']) ? (int) $payload['donation_id'] : 0;
if ($donationId <= 0) Response::error('Debes enviar donation_id válido.', 422);

$estado = strtolower(trim((string) ($payload['estado'] ?? 'aprobado')));
if (!in_array($estado, ['aprobado', 'rechazado', 'en_proceso'], true)) {
    Response::error('Estado inválido. Usa aprobado, rechazado o en_proceso.', 422);
}

$montoReal = null;
if (array_key_exists('monto_real', $payload) && $payload['monto_real'] !== null && $payload['monto_real'] !== '') {
    $raw = is_string($payload['monto_real']) ? str_replace(',', '.', $payload['monto_real']) : $payload['monto_real'];
    $montoReal = (float) $raw;
    if (!is_finite($montoReal) || $montoReal < 0) Response::error('monto_real inválido.', 422);
}

try {
    $pdo = app_db();
    app_db_ensure_schema($pdo);

    // ⚠️ Solo se verifican donaciones del canal QR.
    $find = $pdo->prepare(
        "SELECT id, monto_referencial, monto_real FROM donaciones
         WHERE id = :id AND canal_pago = 'qr_manual' LIMIT 1"
    );
    $find->execute([':id' => $donationId]);
    $donation = $find->fetch(PDO::FETCH_ASSOC);
    if (!is_array($donation)) Response::error('Donación QR no encontrada.', 404);

    // Al aprobar sin monto explícito: usa el real previo; si no hay, el declarado.
    $finalMontoReal = $montoReal;
    if ($estado === 'aprobado' && ($finalMontoReal === null || $finalMontoReal <= 0)) {
        $existingReal = $donation['monto_real'] !== null ? (float) $donation['monto_real'] : 0.0;
        $declared     = (float) ($donation['monto_referencial'] ?? 0);
        $finalMontoReal = $existingReal > 0 ? $existingReal : $declared;
    }

    $pdo->prepare(
        'UPDATE donaciones SET estado = :estado, monto_real = :monto, updated_at = NOW() WHERE id = :id'
    )->execute([':estado' => $estado, ':monto' => $finalMontoReal, ':id' => $donationId]);

    Logger::info('admin_qr', 'Donación QR verificada', [
        'donation_id' => $donationId, 'estado' => $estado,
        'monto_real'  => $finalMontoReal, 'por' => $actor['username'],
    ]);

    $select = $pdo->prepare(
        'SELECT id, nombre, categoria, monto_referencial, monto_real, estado, updated_at
         FROM donaciones WHERE id = :id LIMIT 1'
    );
    $select->execute([':id' => $donationId]);

    Response::success([
        'message' => 'Donación QR actualizada correctamente.',
        'item'    => $select->fetch(PDO::FETCH_ASSOC),
    ]);

} catch (Throwable $e) {
    Logger::error('admin_qr', 'Error verificando donación QR', ['donation_id' => $donationId, 'error' => $e->getMessage()]);
    Response::error('No se pudo actualizar la donación QR.', 500);
}
```

**Por qué el `monto_real` es editable:** el donante escribe "500" pero transfiere 480 — o transfiere
de más. El admin **ve la captura** y registra lo que realmente entró. Este es el corazón del canal
QR: sin ese input, la contabilidad se basaría en promesas.

### 12.5 Visor de comprobante con zoom

Estado mínimo en JS (el CSS lo aporta la nueva marca):

```js
const qrState = { items: [], byId: {}, proofToken: 0, currentZoom: 1 };

function setQrProofZoom(level) {
  const img  = document.getElementById('qr-review-proof-img');
  const wrap = document.getElementById('qr-review-proof-wrap');
  if (!img || !wrap) return;

  const safe = Math.max(1, Math.min(4, Number(level) || 1));   // límites 1x–4x
  qrState.currentZoom = safe;
  img.style.transform = 'scale(' + safe.toFixed(2) + ')';
  wrap.classList.toggle('is-zoomed', safe > 1);                // ← el CSS decide cómo se ve
}
```

- Botones `−` / `1x` / `+` mueven en pasos de `0.25`.
- Clic sobre la imagen alterna entre `1x` y `2x`.
- `proofToken` es un contador que se incrementa al abrir el modal: si el usuario abre otra fila
  mientras la imagen anterior aún carga, se descarta la respuesta vieja (evita mostrar el
  comprobante equivocado, un bug real que costó caro).

### 12.6 Exportación a Excel sin librerías

`api/admin/donations/export.php` genera **una tabla HTML** con `Content-Type:
application/vnd.ms-excel`. Excel y LibreOffice la abren como hoja de cálculo con formato. Cero
dependencias, funciona en cualquier hosting.

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/utils/Logger.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/categories.php';
require_once dirname(__DIR__) . '/auth.php';

Response::handleOptions();
app_admin_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') Response::error('Método no permitido.', 405);

try {
    $pdo = app_db();
    app_db_ensure_schema($pdo);

    $rows = $pdo->query(
        "SELECT id, nombre, documento, correo, telefono, tipo_aportante, categoria,
                monto_referencial, monto_real, canal_pago, estado, created_at
         FROM donaciones ORDER BY created_at DESC, id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $generatedAt = new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
    $filename    = 'reporte-donantes-' . $generatedAt->format('Ymd-His') . '.xls';

    $totals = ['count' => 0, 'real' => 0.0];
    foreach ($rows as $row) {
        $totals['count']++;
        $totals['real'] += $row['monto_real'] !== null
            ? (float) $row['monto_real']
            : (float) ($row['monto_referencial'] ?? 0);
    }

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    echo "\xEF\xBB\xBF";                       // ⚠️ BOM UTF-8: sin esto Excel rompe las tildes
    echo app_render_excel_report($rows, $totals, $generatedAt);
    exit;

} catch (Throwable $e) {
    Logger::error('admin_export', 'Error exportando donaciones', ['error' => $e->getMessage()]);
    Response::error('No se pudo exportar el reporte de donantes.', 500);
}

function app_render_excel_report(array $rows, array $totals, DateTimeImmutable $generatedAt): string
{
    ob_start(); ?>
<!DOCTYPE html>
<html><head><meta charset="utf-8" />
<style>
  body      { font-family: Arial, sans-serif; color: #0f172a; }
  table     { border-collapse: collapse; width: 100%; }
  .title    { background: #0f172a; color: #fff; font-size: 22px; font-weight: 700; }
  .header   { background: #2563eb; color: #fff; font-weight: 700; border: 1px solid #1d4ed8; }
  .cell     { border: 1px solid #cbd5e1; background: #fff; }
  .cell-alt { border: 1px solid #cbd5e1; background: #f8fafc; }
  /* Formatos nativos de Excel: */
  .money    { mso-number-format:"S\/ #,##0.00"; }   /* moneda */
  .text     { mso-number-format:"\@"; }             /* fuerza texto: conserva ceros a la izquierda */
  .date     { mso-number-format:"dd\/mm\/yyyy hh:mm"; }
</style></head><body>
<table>
  <tr><td class="title" colspan="10">Reporte de Donantes</td></tr>
  <tr><td colspan="10">Exportado el <?= app_xls($generatedAt->format('d/m/Y H:i')) ?> (America/Lima)</td></tr>
  <tr><td colspan="10"></td></tr>
  <tr>
    <td><strong>Total registros</strong></td><td><?= (int) $totals['count'] ?></td>
    <td><strong>Monto confirmado</strong></td>
    <td class="money"><?= number_format((float) $totals['real'], 2, '.', '') ?></td>
  </tr>
  <tr><td colspan="10"></td></tr>
  <tr>
    <td class="header">ID</td><td class="header">Tipo</td><td class="header">Nombre / Empresa</td>
    <td class="header">DNI / RUC</td><td class="header">Correo</td><td class="header">Teléfono</td>
    <td class="header">Categoría</td><td class="header">Canal</td>
    <td class="header">Monto confirmado</td><td class="header">Fecha</td>
  </tr>
  <?php foreach ($rows as $i => $row):
        $class = $i % 2 === 0 ? 'cell' : 'cell-alt';
        $monto = $row['monto_real'] !== null ? (float) $row['monto_real'] : (float) ($row['monto_referencial'] ?? 0);
        $tipo  = strtolower((string) ($row['tipo_aportante'] ?? '')) === 'empresa' ? 'Empresa' : 'Persona natural';
  ?>
  <tr>
    <td class="<?= $class ?>"><?= (int) ($row['id'] ?? 0) ?></td>
    <td class="<?= $class ?>"><?= app_xls($tipo) ?></td>
    <td class="<?= $class ?>"><?= app_xls((string) ($row['nombre'] ?? '')) ?></td>
    <td class="<?= $class ?> text"><?= app_xls((string) ($row['documento'] ?? '')) ?></td>
    <td class="<?= $class ?>"><?= app_xls((string) ($row['correo'] ?? '')) ?></td>
    <td class="<?= $class ?> text"><?= app_xls((string) ($row['telefono'] ?? '')) ?></td>
    <td class="<?= $class ?>"><?= app_xls(app_resolve_category_label((string) ($row['categoria'] ?? ''), 'General')) ?></td>
    <td class="<?= $class ?>"><?= app_xls((string) ($row['canal_pago'] ?? '')) ?></td>
    <td class="<?= $class ?> money"><?= number_format($monto, 2, '.', '') ?></td>
    <td class="<?= $class ?> date"><?= app_xls(app_xls_date((string) ($row['created_at'] ?? ''))) ?></td>
  </tr>
  <?php endforeach; ?>
</table></body></html>
    <?php
    return (string) ob_get_clean();
}

function app_xls(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_xls_date(string $value): string
{
    $clean = trim($value);
    if ($clean === '') return '';
    try { return (new DateTimeImmutable($clean))->format('d/m/Y H:i'); }
    catch (Throwable $e) { return $clean; }
}
```

**Los tres trucos que hacen que esto funcione:**

1. `"\xEF\xBB\xBF"` (BOM UTF-8) antes de cualquier salida → tildes y ñ correctas en Excel.
2. `mso-number-format:"\@"` en DNI/RUC y teléfonos → Excel no los convierte a notación científica
   ni les come el `0` inicial.
3. Extensión `.xls` (no `.xlsx`): esto es HTML, no un ZIP OOXML. Si necesitas `.xlsx` real, usa
   PhpSpreadsheet — pero consume más memoria y aquí no aporta nada.

**Descarga desde el navegador:**

```js
async function downloadDonationsExport() {
  showMessage('Preparando reporte Excel...', 'ok');
  try {
    const response = await fetch(API_DONATIONS_EXPORT, { credentials: 'include' });
    if (!response.ok) {
      let message = 'No se pudo generar el reporte Excel.';
      try { const data = await response.json(); if (data && data.error) message = data.error; } catch (e) {}
      throw new Error(message);
    }

    const blob = await response.blob();
    const url  = URL.createObjectURL(blob);
    const link = document.createElement('a');
    const stamp = new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '');

    link.href = url;
    link.download = 'reporte-donantes-' + stamp + '.xls';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);

    showMessage('Reporte descargado correctamente.', 'ok');
  } catch (error) {
    showMessage(error.message, 'error');
  }
}
```

### 12.7 Confirmación antes de escribir dinero

Todo lo que toca montos pasa por un modal de confirmación propio (no `confirm()` nativo), con
**fallback** a `window.confirm` si el modal no existe en el DOM:

```js
function showPremiumModal(options) {
  return new Promise((resolve) => {
    const overlay = document.getElementById('app-modal');
    // … referencias a icono, título, cuerpo, botones …

    if (!overlay /* || falta algún nodo */) {
      resolve(window.confirm((options.title || '') + '\n\n' + String(options.body || '').replace(/<[^>]+>/g, '')));
      return;
    }

    // options.type: 'danger' | 'warning' | 'info' → solo cambia clases CSS
    iconContainer.className = 'app-modal__icon icon-' + (options.type || 'info');
    titleEl.textContent = options.title || 'Confirmar acción';
    bodyEl.innerHTML    = options.body  || '¿Estás seguro de continuar?';
    btnConfirm.textContent = options.confirmText || 'Aceptar';
    btnCancel.textContent  = options.cancelText  || 'Cancelar';

    overlay.classList.add('active');

    const cleanup = () => {
      overlay.classList.remove('active');
      btnConfirm.removeEventListener('click', onConfirm);
      btnCancel.removeEventListener('click', onCancel);
    };
    const onConfirm = () => { cleanup(); resolve(true); };
    const onCancel  = () => { cleanup(); resolve(false); };

    btnConfirm.addEventListener('click', onConfirm);
    btnCancel.addEventListener('click', onCancel);
  });
}

// Uso en tesorería:
cashForm.addEventListener('submit', async (e) => {
  e.preventDefault();

  const payload = collectCashPayload();
  const error   = validateCashPayload(payload);
  if (error) { showMessage(error, 'error'); return; }        // valida ANTES de confirmar

  const ok = await showPremiumModal({
    type: 'warning',
    title: 'Confirmación de tesorería',
    body: `Estás registrando un monto físico de <strong>S/ ${payload.monto}</strong> para ${payload.nombre}.
           <br><br>Verifica que el documento y el monto sean exactos antes de guardarlos.`,
    confirmText: 'Procesar transacción',
    cancelText:  'Revisar datos',
  });

  if (!ok) return;
  saveCashDonation(e);
});
```

---

## 13. Paquete de auditoría de fondos

**Qué problema resuelve.** Cuando alguien pregunta *"¿cuánto dinero entró realmente y dónde está?"*,
la base de datos sola no alcanza: Mercado Pago cobra comisiones, retiene fondos hasta la fecha de
liberación, y algunos pagos aprobados nunca actualizaron la BD porque el webhook falló.

`api/admin/donations/auditoria.php` genera **un ZIP descargable** que cruza todo y permite auditar
offline, sin volver a tocar el servidor.

### 13.1 Contenido del ZIP

```
auditoria-AAAAMMDD-HHMMSS/
├── LEEME.txt                    Cómo leer el paquete, en lenguaje llano
├── 00_metadata.json             Parámetros, totales, errores, duración
├── 01_donaciones.csv            Tabla donaciones completa
├── 02_mp_pagos_detalle.csv      ⭐ Un pago por fila CON COMISIONES Y NETO
├── 03_mp_busqueda_cuenta.csv    Barrido de TODOS los pagos de la cuenta MP
├── 04_webhook_logs.csv          Bitácora cruda (explica los pagos perdidos)
├── 05_comprobantes_qr.csv       Donaciones QR + ruta de su imagen
├── 06_resumen.csv               Totales por canal: bruto / comisión / neto
├── 07_maestro.csv               ⭐ TODO cruzado en una sola tabla
├── 08_pagos_rescatados.csv      Pagos que la BD no reflejaba y se recuperaron
├── errores.log
├── tablas/                      Volcado del resto de tablas (censurado)
├── comprobantes/                Copia de las imágenes Yape/Plin
├── logs/                        *.log de la API
└── raw/
    ├── payments/{payment_id}.json   Respuesta cruda de MP por pago
    ├── search/                      Páginas del barrido
    └── liberaciones/                Reporte oficial de liberaciones de MP
```

### 13.2 Los 14 pasos del script

| Paso | Qué hace | Por qué importa |
|---|---|---|
| 1 | **Solicita** a MP el reporte de liberaciones (asíncrono) | MP lo genera en minutos: se pide al inicio y se recoge al final |
| 2 | Identifica el dueño de la cuenta MP (`/users/me`) | Prueba de que el dinero fue a la cuenta correcta |
| 3 | Vuelca **todas** las tablas de la BD (censurando columnas sensibles) | Copia forense completa |
| 4 | Vuelca `donaciones` en detalle | Tabla principal |
| 5 | **Barrido completo** de la cuenta MP, mes a mes | Encuentra pagos que la BD ni sospecha |
| 6 | Por cada donación, resuelve su pago real | ⭐ **Aquí salen las comisiones** |
| 7 | Separa pagos *rescatados* y *huérfanos* | Dinero que existe y la BD no reflejaba |
| 8 | Exporta `webhook_logs` | Prueba de por qué se perdió un pago |
| 9 | Copia comprobantes QR + genera su CSV | Verificación visual offline |
| 10 | Copia los `.log` de la API | |
| 11 | **Tabla maestra** con todo cruzado | El archivo que realmente se analiza |
| 12 | Resumen por canal | Bruto vs comisión vs neto |
| 13 | Recoge el reporte de liberaciones | Conciliación bancaria oficial |
| 14 | Empaqueta en ZIP y borra la carpeta temporal | Los datos personales no se quedan en el servidor |

### 13.3 Cabecera y seguridad del script

```php
<?php
declare(strict_types=1);

/**
 * PAQUETE DE AUDITORÍA DE FONDOS
 * ------------------------------
 * Genera un ZIP con todo lo necesario para auditar en local: base de datos,
 * comisiones reales de Mercado Pago, imágenes de comprobantes, JSON crudos y logs.
 *
 * Requiere sesión de SUPERADMIN. No hay tokens que copiar ni pegar.
 *
 * Por defecto se lleva TODO. Desactiva cosas solo si el hosting corta el script:
 *   ?comprobantes=0   no copia las imágenes
 *   ?liberaciones=0   no pide el reporte oficial de liberaciones
 *   ?logs=0           no incluye los .log
 *   ?busqueda=0       no barre toda la cuenta de MP (lo más lento)
 *   ?desde=2026-01-01&hasta=2026-12-31   acota el rango
 *   ?mantener=1       deja la carpeta temporal en el servidor
 */

// Columnas que NUNCA salen del servidor.
const COLUMNAS_CENSURADAS = ['password_hash', 'password', 'clave', 'token', 'secret', 'access_token'];

@set_time_limit(0);
@ini_set('memory_limit', '768M');
@ignore_user_abort(true);          // si el navegador corta, el ZIP igual se termina

require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/utils/Logger.php';
require_once dirname(__DIR__, 2) . '/config/env.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__) . '/auth.php';

Response::handleOptions();
app_admin_require_superadmin();    // ⚠️ solo superadmin: el ZIP contiene TODA la PII

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') Response::error('Método no permitido.', 405);

// ⚠️ CRÍTICO: el export tarda minutos. Sin esto, la sesión queda bloqueada
// y el panel entero se congela mientras corre.
session_write_close();
```

### 13.4 Carpeta temporal blindada

Mientras se arma, la carpeta contiene datos personales en claro. Se prueba cada ruta candidata
hasta encontrar una escribible y se le planta un `.htaccess` que la cierra:

```php
$sello   = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Ymd-His');
$apiBase = dirname(__DIR__, 2);
$carpeta = '';

foreach ([$apiBase . '/tmp', sys_get_temp_dir(), $apiBase . '/logs'] as $candidata) {
    $raizTmp = rtrim($candidata, '/\\');
    $intento = $raizTmp . '/_auditoria_' . $sello;

    if (@mkdir($intento . '/raw/payments', 0775, true) || is_dir($intento . '/raw/payments')) {
        $carpeta = $intento;

        $candado = $raizTmp . '/.htaccess';
        if (!file_exists($candado)) {
            @file_put_contents($candado, "Require all denied\nDeny from all\nOptions -Indexes\n");
        }
        break;
    }
}

if ($carpeta === '') {
    Response::error('No se pudo crear la carpeta temporal. Revisa permisos de escritura en api/.', 500);
}

foreach (['/raw/search', '/raw/liberaciones', '/tablas', '/logs'] as $sub) {
    @mkdir($carpeta . $sub, 0775, true);
}
```

### 13.5 Cliente HTTP a la API de Mercado Pago

El SDK no expone todos los endpoints (liberaciones, búsquedas avanzadas), así que se usa cURL
directo. **Con reintentos y respetando el rate limit:**

```php
/**
 * GET autenticado a la API de MP con reintentos.
 * @param array<int,string> $errores  se acumulan aquí; no se lanzan excepciones
 * @return array<string,mixed>|null
 */
function mp_get(string $url, string $token, array &$errores): ?array
{
    for ($intento = 1; $intento <= 3; $intento++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $status >= 200 && $status < 300) {
            $data = json_decode((string) $body, true);
            return is_array($data) ? $data : null;
        }

        if ($status === 429) { sleep(2 * $intento); continue; }   // rate limit → backoff
        if ($status === 404) return null;                          // no existe: no es error

        if ($intento === 3) {
            $errores[] = sprintf('GET %s → HTTP %d %s', $url, $status,
                $err !== '' ? $err : substr((string) $body, 0, 200));
        }

        usleep(300000);
    }

    return null;
}
```

### 13.6 ⭐ El corazón: extraer las comisiones reales

Un pago de Mercado Pago trae `fee_details[]`: cada comisión con su `amount` y quién la paga
(`fee_payer`). **Solo las de `collector` reducen lo que recibe la organización.**

```php
/**
 * Aplana un payment de MP a una fila de CSV con el desglose económico real.
 * @param array<string,mixed> $pago
 * @return array<string,mixed>
 */
function aplanar_pago(array $pago, int $donacionId): array
{
    $detalles = is_array($pago['transaction_details'] ?? null) ? $pago['transaction_details'] : [];
    $fees     = is_array($pago['fee_details'] ?? null)         ? $pago['fee_details']         : [];
    $payer    = is_array($pago['payer'] ?? null)               ? $pago['payer']               : [];
    $doc      = is_array($payer['identification'] ?? null)     ? $payer['identification']     : [];

    $comisionCollector = 0.0;
    $comisionTotal     = 0.0;
    $tiposComision     = [];

    foreach ($fees as $fee) {
        if (!is_array($fee)) continue;

        $monto = (float) ($fee['amount'] ?? 0);
        $comisionTotal += $monto;

        // ⚠️ Solo las comisiones con fee_payer='collector' te descuentan a TI.
        if ((string) ($fee['fee_payer'] ?? '') === 'collector') {
            $comisionCollector += $monto;
        }

        $tiposComision[] = (string) ($fee['type'] ?? '?') . ':' . number_format($monto, 2, '.', '');
    }

    $bruto = (float) ($pago['transaction_amount'] ?? 0);

    // net_received_amount es la cifra oficial de MP; el cálculo es el respaldo.
    $neto = isset($detalles['net_received_amount'])
        ? (float) $detalles['net_received_amount']
        : ($bruto - $comisionCollector);

    return [
        'payment_id'           => (string) ($pago['id'] ?? ''),
        'donacion_id_bd'       => $donacionId > 0 ? (string) $donacionId : (string) ($pago['external_reference'] ?? ''),
        'external_reference'   => (string) ($pago['external_reference'] ?? ''),
        'status'               => (string) ($pago['status'] ?? ''),
        'status_detail'        => (string) ($pago['status_detail'] ?? ''),
        'live_mode'            => !empty($pago['live_mode']) ? 'si' : 'no',   // ⚠️ 'no' = pago de prueba
        'date_created'         => (string) ($pago['date_created'] ?? ''),
        'date_approved'        => (string) ($pago['date_approved'] ?? ''),
        'money_release_date'   => (string) ($pago['money_release_date'] ?? ''),   // cuándo se puede retirar
        'money_release_status' => (string) ($pago['money_release_status'] ?? ''),
        'currency_id'          => (string) ($pago['currency_id'] ?? ''),
        'monto_bruto'          => number_format($bruto, 2, '.', ''),
        'total_paid_amount'    => number_format((float) ($detalles['total_paid_amount'] ?? 0), 2, '.', ''),
        'comision_collector'   => number_format($comisionCollector, 2, '.', ''),
        'comision_total'       => number_format($comisionTotal, 2, '.', ''),
        'monto_neto_recibido'  => number_format($neto, 2, '.', ''),
        'detalle_comisiones'   => implode(' | ', $tiposComision),
        'monto_devuelto'       => number_format((float) ($pago['transaction_amount_refunded'] ?? 0), 2, '.', ''),
        'payment_method_id'    => (string) ($pago['payment_method_id'] ?? ''),
        'payment_type_id'      => (string) ($pago['payment_type_id'] ?? ''),
        'installments'         => (string) ($pago['installments'] ?? ''),
        'operation_type'       => (string) ($pago['operation_type'] ?? ''),
        'collector_id'         => (string) ($pago['collector_id'] ?? ''),
        'payer_email'          => (string) ($payer['email'] ?? ''),
        'payer_nombre'         => trim((string) ($payer['first_name'] ?? '') . ' ' . (string) ($payer['last_name'] ?? '')),
        'payer_documento'      => trim((string) ($doc['type'] ?? '') . ' ' . (string) ($doc['number'] ?? '')),
        'description'          => (string) ($pago['description'] ?? ''),
        'origen_del_dato'      => '',   // se rellena en el paso 6
    ];
}
```

> ⚠️ **`live_mode = no` significa pago de prueba.** Si aparece en producción, alguien dejó
> credenciales de sandbox activas. Es el primer campo que debe revisar un auditor.

### 13.7 ⭐ El rescate de pagos perdidos

Esta es la lógica que **recupera dinero real que la base de datos no reflejaba**:

```php
foreach ($donaciones as $d) {
    $donId = (string) ($d['id'] ?? '');
    $pid   = trim((string) ($d['mp_payment_id'] ?? ''));
    $pago  = null; $origen = ''; $fueRescatado = false;

    if ($pid !== '' && ctype_digit($pid)) {
        // Caso normal: la BD sabe cuál es el pago.
        $pago = mp_get('https://api.mercadopago.com/v1/payments/' . $pid, $accessToken, $errores);
        $origen = 'consulta directa por payment_id';

        if (!is_array($pago) && isset($porPaymentId[$pid])) {
            $pago = $porPaymentId[$pid];
            $origen = 'barrido de la cuenta (la consulta directa falló)';
        }

        usleep(180000);                      // ⚠️ respeta el rate limit de MP

    } elseif ($donId !== '' && (string) ($d['canal_pago'] ?? '') === 'mercadopago') {
        // ⭐ La BD no tiene payment_id: puede que el pago SÍ exista y el webhook fallara.
        if (isset($porExternalRef[$donId])) {
            $pago   = elegir_mejor_pago($porExternalRef[$donId]);
            $origen = 'RESCATADO del barrido por external_reference';
        } else {
            $resp = mp_get(
                'https://api.mercadopago.com/v1/payments/search?' . http_build_query([
                    'external_reference' => $donId,
                    'limit'              => 10,
                ]),
                $accessToken, $errores
            );

            $resultados = is_array($resp['results'] ?? null) ? $resp['results'] : [];
            if ($resultados !== []) {
                $pago   = elegir_mejor_pago($resultados);
                $origen = 'RESCATADO por búsqueda de external_reference';
            }

            usleep(180000);
        }

        $fueRescatado = is_array($pago);
    }

    if (!is_array($pago)) continue;

    // Se guarda el JSON crudo: prueba documental.
    $pidReal = (string) ($pago['id'] ?? '');
    if ($pidReal !== '') {
        file_put_contents($carpetaRaw . '/payments/' . $pidReal . '.json', json_pretty($pago));
        $idsUsados[$pidReal] = true;
    }

    $fila = aplanar_pago($pago, (int) $donId);
    $fila['origen_del_dato'] = $origen;

    $filasPagos[] = $fila;
    $pagosPorDonacion[$donId] = $fila;

    if ($fueRescatado) {
        $rescatados[] = [
            'donacion_id'          => $donId,
            'nombre'               => $d['nombre'] ?? '',
            'documento'            => $d['documento'] ?? '',
            'estado_en_bd'         => $d['estado'] ?? '',
            'monto_declarado'      => $d['monto_referencial'] ?? '',
            'mp_preference_id'     => $d['mp_preference_id'] ?? '',
            'payment_id_encontrado'=> $pidReal,
            'mp_status'            => $fila['status'],
            'monto_bruto'          => $fila['monto_bruto'],
            'comision'             => $fila['comision_collector'],
            'monto_neto_recibido'  => $fila['monto_neto_recibido'],
            'date_approved'        => $fila['date_approved'],
            'como_se_encontro'     => $origen,
        ];
    }
}

// Pagos que existen en MP y NO corresponden a ninguna donación de la BD:
$meta['pagos_sin_registro_en_bd'] = array_values(array_diff(array_keys($porPaymentId), array_keys($idsUsados)));
```

```php
/**
 * De varios pagos con el mismo external_reference se queda con el aprobado;
 * si ninguno lo está, con el más reciente.
 * @param array<int,mixed> $pagos
 * @return array<string,mixed>|null
 */
function elegir_mejor_pago(array $pagos): ?array
{
    $mejor = null;
    foreach ($pagos as $pago) {
        if (!is_array($pago)) continue;
        if (strtolower((string) ($pago['status'] ?? '')) === 'approved') return $pago;
        if ($mejor === null || (string) ($pago['date_created'] ?? '') > (string) ($mejor['date_created'] ?? '')) {
            $mejor = $pago;
        }
    }
    return $mejor;
}
```

### 13.8 La tabla maestra (`07_maestro.csv`)

Una fila por donación, con la BD y Mercado Pago lado a lado. **Este es el archivo que se abre en
Excel para auditar.**

```
donacion_id · created_at · nombre · documento · tipo_aportante · correo · telefono · categoria
canal_pago · proveedor_pago · estado_bd · moneda · monto_referencial · monto_real_bd
referencia_pago · mp_preference_id · mp_payment_id · mp_status · mp_live_mode
mp_bruto · mp_comision · mp_neto · mp_metodo · mp_fecha_aprobado · mp_fecha_liberacion
mp_origen_del_dato · imagen_comprobante · clasificacion_preliminar
```

Discrepancias que saltan a la vista al ordenar por columna:

| Señal | Significado |
|---|---|
| `estado_bd = pendiente` **y** `mp_status = approved` | El webhook falló: hay dinero sin contabilizar |
| `mp_live_mode = no` en producción | Pago de prueba mezclado con los reales |
| `monto_real_bd ≠ mp_bruto` | El monto registrado no coincide con lo cobrado |
| `mp_neto` ≪ `mp_bruto` | Comisión alta (revisar método de pago / cuotas) |
| `mp_origen_del_dato` empieza con `RESCATADO` | Ese pago se recuperó en la auditoría |
| `mp_fecha_liberacion` futura | Dinero aprobado pero aún retenido por MP |

### 13.9 Utilidades de escritura de CSV

```php
/** CSV con BOM UTF-8 y encabezados fijos. */
function escribir_csv(string $ruta, array $filas, array $encabezados): void
{
    $fh = fopen($ruta, 'w');
    if ($fh === false) return;

    fwrite($fh, "\xEF\xBB\xBF");        // Excel + tildes
    fputcsv($fh, $encabezados);

    foreach ($filas as $fila) {
        fputcsv($fh, normalizar_fila((array) $fila, $encabezados));
    }

    fclose($fh);
}

/** Igual, pero en streaming desde un PDOStatement (tablas grandes, memoria constante). */
function escribir_csv_stream(string $ruta, PDOStatement $stmt, array $encabezados): int
{
    $fh = fopen($ruta, 'w');
    if ($fh === false) return 0;

    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, $encabezados);

    $total = 0;
    while ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($fh, normalizar_fila($fila, $encabezados));
        $total++;
    }

    fclose($fh);
    return $total;
}

/** Ordena la fila según los encabezados y censura columnas sensibles. */
function normalizar_fila(array $fila, array $encabezados): array
{
    $salida = [];
    foreach ($encabezados as $columna) {
        $valor = $fila[$columna] ?? '';

        if (in_array(strtolower($columna), COLUMNAS_CENSURADAS, true)) {
            $salida[] = '[CENSURADO]';
            continue;
        }

        if (is_array($valor) || is_object($valor)) {
            $valor = json_encode($valor, JSON_UNESCAPED_UNICODE);
        }

        $salida[] = (string) $valor;
    }
    return $salida;
}

function json_pretty($datos): string
{
    return (string) json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
```

### 13.10 Empaquetado y limpieza

```php
$meta['duracion_segundos'] = round(microtime(true) - $inicioCronometro, 1);
$meta['total_errores']     = count($errores);

file_put_contents($carpeta . '/00_metadata.json', json_pretty($meta));
file_put_contents($carpeta . '/errores.log', $errores === [] ? "Sin errores.\n" : implode("\n", $errores) . "\n");
file_put_contents($carpeta . '/LEEME.txt', texto_leeme());

$nombreZip = 'auditoria-' . $sello . '.zip';
$rutaZip   = dirname($carpeta) . '/' . $nombreZip;

if (!class_exists('ZipArchive')) {
    Response::error('ZipArchive no está disponible. La carpeta quedó en ' . $carpeta
        . ' — descárgala por FTP y bórrala después.', 500);
}

$zip = new ZipArchive();
if ($zip->open($rutaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    Response::error('No se pudo crear el ZIP. La carpeta quedó en ' . $carpeta, 500);
}

$iterador = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($carpeta, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterador as $archivo) {
    /** @var SplFileInfo $archivo */
    if (!$archivo->isFile()) continue;
    $ruta = $archivo->getRealPath();
    $relativa = 'auditoria-' . $sello . '/' . str_replace('\\', '/', substr($ruta, strlen($carpeta) + 1));
    $zip->addFile($ruta, $relativa);
}

$zip->close();

if (!$mantenerCarpeta) borrar_recursivo($carpeta);   // ⚠️ la PII no se queda en el servidor

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $nombreZip . '"');
header('Content-Length: ' . (string) filesize($rutaZip));
header('Cache-Control: no-store');
readfile($rutaZip);
@unlink($rutaZip);                                    // ⚠️ el ZIP tampoco
exit;
```

### 13.11 Notas operativas

- **Tarda minutos** (una llamada HTTP por donación con pausas de 180 ms). En el navegador, el botón
  debe deshabilitarse y avisar: *"puede tardar varios minutos, no cierres esta pestaña"*.
- Si el hosting corta por `max_execution_time`, usa los flags: `?busqueda=0&comprobantes=0`.
- Si `ZipArchive` no existe, la carpeta queda en el servidor y el mensaje de error dice dónde.
- **Alternativa para campañas grandes:** ejecútalo por CLI con cron y deja el ZIP en una carpeta
  protegida, en lugar de generarlo bajo demanda desde el navegador.

---

## 14. Frontend público: contrato JS

La API es agnóstica del framework. Esta sección define **qué tiene que hacer el frontend**, sea
vanilla, React o Vue. Los ejemplos son vanilla, como el original.

### 14.1 Resolución de la URL base de la API

Un solo punto de verdad. Debe funcionar en: XAMPP local, dev server en otro puerto, subdominio
propio y subcarpeta.

```js
function resolveApiBase() {
  // 1) Override explícito: window.__API_BASE__ = 'https://api.midominio.org';
  const configured = window.__API_BASE__;
  if (typeof configured === 'string' && configured.trim()) {
    return configured.replace(/\/+$/, '');
  }

  const { protocol, hostname, port, pathname } = window.location;
  const firstSegment = pathname.split('/').filter(Boolean)[0] || '';

  // 2) Subdominio dedicado: la API cuelga de la raíz
  if (/^(www\.)?donaciones\.midominio\.org$/i.test(hostname)) {
    return `${protocol}//${window.location.host}/api`;
  }

  // 3) Dev server (node/vite en :3000) + API en Apache
  if ((hostname === 'localhost' || hostname === '127.0.0.1') && port === '3000') {
    return 'http://127.0.0.1/mi_proyecto/api';
  }

  // 4) Instalado en subcarpeta: /mi_proyecto/api
  if (firstSegment && !firstSegment.includes('.')) {
    return `${protocol}//${window.location.host}/${firstSegment}/api`;
  }

  // 5) Mismo directorio
  return 'api';
}

const API_BASE      = resolveApiBase();
const API_CREATE    = `${API_BASE}/donation/create.php`;
const API_QR_SUBMIT = `${API_BASE}/donation/qr_submit.php`;
const API_RECONCILE = `${API_BASE}/donation/reconcile.php`;
const API_DONORS    = `${API_BASE}/donors/list.php`;
```

### 14.2 Flujo del formulario en dos pasos

```
PASO 1 — Datos del aporte                    PASO 2 — Solo si eligió QR
┌────────────────────────────────┐          ┌────────────────────────────────┐
│ [Persona] [Empresa]            │          │ ← volver                       │
│ Nombre / Razón social          │          │ 01 · Transferencia             │
│ DNI (8) / RUC (11)             │          │   QR de la organización        │
│ Correo · Teléfono              │          │   "Transfiere S/ 150 exactos"  │
│ Categoría (dropdown accesible) │          │ 02 · Comprobante               │
│ Montos: [50][100][250][Otro]   │          │   [Subir imagen]               │
│ ☐ Donar de forma anónima       │   ──►    │   vista previa                 │
│ ☐ Acepto términos  [ver]       │          │   [Revisar y enviar]           │
│                                │          │      ↓ modal de confirmación   │
│ [Donar con Mercado Pago]       │          │      ↓ modal de éxito          │
│ [Donar con Yape / Plin]        │          └────────────────────────────────┘
└────────────────────────────────┘
```

Reglas de UX que conviene conservar:

- **El tipo de aportante cambia el formulario** (persona → DNI de 8 dígitos; empresa → RUC de 11 y
  "Razón social"). Valida en el front *y* en el servidor.
- **Montos sugeridos + "Otro"**: el botón "Otro" revela un input numérico.
- **Mínimos distintos por canal**: MP suele tener mínimo más alto (la comisión fija hace inviables
  los micro-aportes); QR puede aceptar montos pequeños. Ambos vienen del `.env`.
- **Los términos abren un modal** y el checkbox se marca al aceptar.
- **El dropdown de categorías** es accesible: `role="listbox"`, navegación con flechas, typeahead
  por letras, `Escape` cierra y devuelve el foco al trigger.

### 14.3 Ir a Mercado Pago

```js
const DONATION_PENDING_KEY = 'donacion_checkout_pendiente';

function buildDonationPayload() {
  return {
    nombre:          donorName(),
    documento:       donorDocument(),
    correo:          donorEmail(),
    telefono:        donorPhone(),
    categoria:       selectedCategoryLabel(),
    monto:           getActiveAmount(),
    moneda:          'PEN',
    tipo_aportante:  donorType === 'company' ? 'empresa' : 'persona',
    visible_publico: !anonymousCheck.checked,
    acepta_terminos: termsCheck.checked,
  };
}

async function createPreference(payload) {
  const response = await fetch(API_CREATE, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body:    JSON.stringify(payload),
  });

  const raw = await response.text();
  let data = null;
  try { data = raw ? JSON.parse(raw) : null; }
  catch (e) { throw new Error('La API de pagos no respondió un JSON válido.'); }

  if (!response.ok || !data || data.success !== true || !data.init_point) {
    throw new Error((data && data.error) || 'No se pudo iniciar el checkout.');
  }

  return data;
}

// ⚠️ CLAVE: guarda las llaves ANTES de redirigir. Al volver, el navegador
// puede no traer payment_id en la URL y esto es lo único que permite reconciliar.
function savePendingCheckout(result, payload) {
  try {
    localStorage.setItem(DONATION_PENDING_KEY, JSON.stringify({
      donation_id:   Number(result.donation_id) || 0,
      preference_id: String(result.preference_id || '').trim(),
      amount:        Number(payload.monto || 0),
      ts:            Date.now(),
    }));
  } catch (e) { /* modo privado: seguimos sin bloquear */ }
}

async function openMercadoPagoFlow() {
  const payload = buildDonationPayload();
  const error = validateDonationForm(payload);
  if (error) { showFormError(error); return; }

  setCheckoutLoading(true);
  try {
    const result = await createPreference(payload);
    savePendingCheckout(result, payload);
    window.location.href = result.init_point;
  } catch (err) {
    showFormError(err.message);
    setCheckoutLoading(false);
  }
}
```

> 💡 **Navegadores embebidos** (VS Code, apps con WebView): el checkout de MP suele fallar por
> restricciones de cookies de terceros. Detéctalos por `userAgent` (`/Electron|\bCode\//i`) y abre
> el `init_point` con `window.open(url, '_blank', 'noopener,noreferrer')`, avisando al usuario.
> Lo mismo con Brave y sus Shields en sandbox.

### 14.4 Reconciliación al volver del checkout

Se ejecuta al cargar la página. **Es la segunda red de seguridad del sistema.**

```js
function parsePositiveInt(value) {
  const raw = String(value || '').trim();
  return /^\d+$/.test(raw) ? Number(raw) : 0;
}

function readPendingCheckout() {
  try {
    const raw = localStorage.getItem(DONATION_PENDING_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch (e) { return null; }
}

function cleanCheckoutQueryParams() {
  if (!window.history || typeof window.history.replaceState !== 'function') return;
  window.history.replaceState({}, document.title, window.location.origin + window.location.pathname);
}

async function tryAutoReconcileCheckout() {
  const params = new URLSearchParams(window.location.search);

  const cameBack = params.has('donacion') || params.has('payment_id')
                || params.has('collection_id') || params.has('preference_id');
  if (!cameBack) return;

  const pending = readPendingCheckout();
  const payload = {
    donation_id:   parsePositiveInt(pending && pending.donation_id),
    preference_id: String(params.get('preference_id') || (pending && pending.preference_id) || '').trim(),
    payment_id:    parsePositiveInt(params.get('payment_id') || params.get('collection_id')),
  };

  if (payload.donation_id <= 0 && payload.preference_id === '' && payload.payment_id <= 0) {
    cleanCheckoutQueryParams();
    return;
  }

  try {
    const response = await fetch(API_RECONCILE, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body:    JSON.stringify(payload),
    });

    const raw  = await response.text();
    const data = raw ? JSON.parse(raw) : null;

    if (response.ok && data && data.success === true) {
      if (data.reconciled === true && (data.status === 'aprobado' || data.status === 'rechazado')) {
        localStorage.removeItem(DONATION_PENDING_KEY);   // caso cerrado
      }
      // Evento para que la UI muestre el mensaje con el diseño propio.
      window.dispatchEvent(new CustomEvent('donacion:reconciliada', { detail: data }));
    }
  } catch (e) {
    // Si falla, el webhook resolverá después. No bloquea la UX.
  } finally {
    cleanCheckoutQueryParams();   // deja la URL limpia
  }
}

document.addEventListener('DOMContentLoaded', tryAutoReconcileCheckout);
```

### 14.5 Envío del comprobante QR

```js
async function submitQrDonation(payload, file) {
  const form = new FormData();
  form.append('nombre',          String(payload.nombre || ''));
  form.append('documento',       String(payload.documento || ''));
  form.append('correo',          String(payload.correo || ''));
  form.append('telefono',        String(payload.telefono || ''));
  form.append('categoria',       String(payload.categoria || ''));
  form.append('monto',           String(payload.monto || 0));
  form.append('moneda',          String(payload.moneda || 'PEN'));
  form.append('tipo_aportante',  String(payload.tipo_aportante || 'persona'));
  form.append('visible_publico', payload.visible_publico ? '1' : '0');
  form.append('acepta_terminos', payload.acepta_terminos ? '1' : '0');
  form.append('proof_file',      file);

  // ⚠️ NO pongas Content-Type: el navegador debe generar el boundary de multipart.
  const response = await fetch(API_QR_SUBMIT, { method: 'POST', body: form });

  const raw = await response.text();
  let data = null;
  try { data = raw ? JSON.parse(raw) : null; }
  catch (e) { throw new Error('La API no respondió un JSON válido.'); }

  if (!response.ok || !data || data.success !== true) {
    throw new Error((data && data.error) || 'No se pudo registrar el comprobante.');
  }

  return data;
}
```

Vista previa antes de enviar (y liberación del objeto URL para no filtrar memoria):

```js
let previewUrl = '';

proofFileInput.addEventListener('change', () => {
  const file = proofFileInput.files && proofFileInput.files[0];
  if (previewUrl) { URL.revokeObjectURL(previewUrl); previewUrl = ''; }
  if (!file) { proofSubmit.disabled = true; proofPreview.innerHTML = ''; return; }

  previewUrl = URL.createObjectURL(file);
  proofPreview.innerHTML = `<img src="${previewUrl}" alt="Vista previa del comprobante" />`;
  proofSubmit.disabled = false;
});
```

### 14.6 Dashboard en vivo

```js
async function loadDonationData() {
  try {
    const response = await fetch(API_DONORS, {
      method: 'GET', headers: { Accept: 'application/json' }, cache: 'no-store',
    });
    if (!response.ok) return;

    const data = await response.json();
    if (!data || data.success !== true) return;

    updateHeroStats(data);                       // total, %, barra, contadores
    renderDonorsList(data.donadores || []);      // feed
    updateTicker(data.donadores || []);          // cinta
    applyCategoryTotals(data.categorias || []);  // ranking / mapa
    syncCategorySelect(data.categorias_catalogo || []);   // opciones del formulario
  } catch (e) {
    console.warn('No se pudo actualizar el panel de donaciones.', e);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  loadDonationData();
  setInterval(loadDonationData, 30000);    // refresco en vivo
});
```

**Detalles que hacen que se vea profesional:**

- **Contadores animados** con `easeOut` en la primera carga (no en cada refresco: parpadea).
- La barra de progreso anima su `width`; el porcentaje se limita a `[0, 100]`.
- **Fechas relativas**: `Hoy` / `Ayer` / `14 sep 2026`.
- **Avatar por iniciales** del donante (2 letras) cuando no hay foto.
- **`escapeHtml()` obligatorio** en todo lo que venga de la API antes de meterlo con `innerHTML`.
  El nombre del donante es entrada de usuario:

```js
function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
```

### 14.7 Mapa geográfico (opcional)

Si la taxonomía es geográfica, el original usa **MapLibre GL** (open source, sin API key) con un
GeoJSON local y extrusión 3D proporcional a lo recaudado:

- `data/mapa.geojson` con los polígonos (simplifícalo con mapshaper: < 200 KB).
- Se cruza cada *feature* con su categoría por nombre normalizado.
- El color sale de una expresión `interpolate` sobre el monto; **los colores vienen de los tokens
  CSS de la nueva marca**, no hardcodeados.
- En móvil se desactiva la rotación/inclinación y se ajusta el `zoom` inicial por viewport.

Si la taxonomía **no** es geográfica, reemplázalo por un ranking en barras: más simple, más rápido
e igual de efectivo.

---

## 15. Capa de diseño: cómo re-skinear sin romper nada

Esta es **la sección más importante para la nueva organización**. El backend se copia; el diseño
es enteramente suyo. Lo que sigue define la frontera exacta entre ambos para que rediseñar no
rompa la lógica, y programar no rompa el diseño.

### 15.1 El contrato: quién decide qué

| Capa | Decide | Nunca hace |
|---|---|---|
| **PHP** | Datos, estados, montos, permisos | Emitir HTML de UI, colores o estilos |
| **JS** | Traer datos, insertar texto, **alternar clases de estado** | Definir colores, tamaños, sombras, tipografías |
| **CSS** | Absolutamente todo lo visual | Contener datos |

**Regla única e innegociable:** el JavaScript **nunca** asigna un color. Alterna una clase y el CSS
de la organización decide cómo se ve.

```js
// ❌ MAL — el color queda enterrado en el JS, imposible de re-skinear
errorEl.style.color = tone === 'success' ? '#4ade80' : '#f87171';

// ✅ BIEN — el CSS de cada marca decide
errorEl.classList.toggle('is-success', tone === 'success');
errorEl.classList.toggle('is-error',   tone !== 'success');
```

> ⚠️ El proyecto original tiene **dos deudas** en este punto que conviene no heredar:
> 1. `donation-form.js` asigna `style.color` con hex literales (arriba).
> 2. El panel admin define los tokens en un `<style>` dentro del HTML, en vez de un `tokens.css`.
>
> Al reimplementar, arregla ambas: mueve todo color a `css/tokens.css`.

### 15.2 `css/tokens.css` — el único archivo que cambia por marca

```css
/* ============================================================================
   TOKENS DE MARCA — Único archivo que la organización edita para re-skinear.
   Todo el resto del CSS consume estas variables; ningún componente tiene
   colores literales.
   ========================================================================= */
:root {
  /* ── Identidad ─────────────────────────────────────────────────────────── */
  --brand-primary:        #0F172A;   /* color principal de la marca */
  --brand-primary-soft:   #1E293B;
  --brand-accent:         #3B82F6;   /* acento: CTA, foco, barra de progreso */
  --brand-accent-strong:  #2563EB;
  --brand-gradient:       linear-gradient(135deg, var(--brand-accent), var(--brand-primary));

  /* ── Superficies ───────────────────────────────────────────────────────── */
  --surface-page:    #F4F7FB;
  --surface-card:    #FFFFFF;
  --surface-raised:  #FFFFFF;
  --surface-sunken:  #F8FAFC;
  --surface-overlay: rgba(15, 23, 42, 0.55);   /* fondo de modales */

  /* ── Texto ─────────────────────────────────────────────────────────────── */
  --text-strong:  #0F172A;
  --text-body:    #334155;
  --text-muted:   #64748B;
  --text-inverse: #FFFFFF;

  /* ── Bordes y elevación ────────────────────────────────────────────────── */
  --border-soft:   #E2E8F0;
  --border-strong: #CBD5E1;
  --radius-sm: 6px;
  --radius-md: 10px;
  --radius-lg: 16px;
  --shadow-sm: 0 1px 2px rgba(15, 23, 42, .06);
  --shadow-md: 0 8px 24px rgba(15, 23, 42, .10);
  --shadow-lg: 0 24px 60px rgba(15, 23, 42, .18);

  /* ── Estados semánticos (los mismos nombres en toda la app) ────────────── */
  --state-ok-bg:     #DCFCE7;  --state-ok-text:     #166534;  --state-ok-border:     #86EFAC;
  --state-warn-bg:   #FEF9C3;  --state-warn-text:   #854D0E;  --state-warn-border:   #FDE68A;
  --state-danger-bg: #FEE2E2;  --state-danger-text: #991B1B;  --state-danger-border: #FECACA;
  --state-info-bg:   #DBEAFE;  --state-info-text:   #1E40AF;  --state-info-border:   #BFDBFE;

  /* ── Escala de recaudación (mapa / ranking / barras) ───────────────────── */
  --scale-0: #E2E8F0;   /* sin aportes */
  --scale-1: #BFDBFE;
  --scale-2: #60A5FA;
  --scale-3: #2563EB;
  --scale-4: #1E3A8A;   /* máximo */

  /* ── Tipografía ────────────────────────────────────────────────────────── */
  --font-display: 'Plus Jakarta Sans', system-ui, sans-serif;
  --font-body:    'Plus Jakarta Sans', system-ui, sans-serif;
  --font-mono:    ui-monospace, 'SF Mono', Menlo, monospace;   /* montos, IDs */

  /* ── Ritmo vertical ────────────────────────────────────────────────────── */
  --space-1: .25rem; --space-2: .5rem; --space-3: .75rem; --space-4: 1rem;
  --space-6: 1.5rem; --space-8: 2rem;  --space-12: 3rem;   --space-16: 4rem;

  /* ── Movimiento ────────────────────────────────────────────────────────── */
  --ease:       cubic-bezier(.4, 0, .2, 1);
  --duration:   240ms;
}

/* Tema oscuro, si la marca lo tiene */
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --surface-page:   #0B1120;
    --surface-card:   #111827;
    --surface-sunken: #0F172A;
    --text-strong:    #F8FAFC;
    --text-body:      #CBD5E1;
    --text-muted:     #94A3B8;
    --border-soft:    #1F2937;
    --border-strong:  #334155;
  }
}

:root[data-theme="dark"] { /* mismas sobreescrituras, forzadas */ }

/* Respeta a quien pidió menos movimiento */
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: .01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: .01ms !important;
  }
}
```

**Para re-skinear:** se edita **solo este archivo**. Si hay que tocar otro CSS para cambiar un
color, ese CSS tiene una deuda: extrae el valor a un token.

### 15.3 Contrato de clases de estado (JS ↔ CSS)

Estas son **todas** las clases y atributos que el JavaScript alterna. El CSS de la nueva marca debe
definirlas; el JS no debe inventar otras sin documentarlas aquí.

| Clase / atributo | Dónde | Significado |
|---|---|---|
| `.is-active` | pasos, pestañas, botón de monto, tipo de aportante | Elemento seleccionado / paso visible |
| `.is-open` | dropdown de categorías | Panel desplegado |
| `.is-selected` | opción de categoría | Opción elegida |
| `.is-focused` | opción de categoría | Opción bajo el cursor / teclado |
| `.is-zoomed` | visor de comprobante | Zoom > 1x |
| `.is-loading` | botones | Operación en curso (spinner + `disabled`) |
| `.is-invalid` | campos del formulario | Error de validación |
| `.is-ok` / `.is-error` | mensaje de estado del admin | Éxito / fallo |
| `.active` | overlay de modal | Modal abierto |
| `.badge.aprobado` / `.pendiente` / `.rechazado` / `.en_proceso` | tabla admin | Estado de la donación |
| `.icon-danger` / `.icon-warning` / `.icon-info` | icono del modal | Tono del modal de confirmación |
| `[hidden]` | modales, hints | Oculto (el CSS debe forzar `display:none !important` si hay competencia de especificidad) |
| `[aria-expanded]`, `[aria-selected]`, `[aria-hidden]` | dropdown, modales | Accesibilidad — **obligatorios** |

Ejemplo de cómo la nueva marca los implementa, usando solo tokens:

```css
.badge                 { padding: .25rem .6rem; border-radius: 999px; font-size: .78rem; font-weight: 700; }
.badge.aprobado        { background: var(--state-ok-bg);     color: var(--state-ok-text); }
.badge.pendiente,
.badge.en_proceso      { background: var(--state-warn-bg);   color: var(--state-warn-text); }
.badge.rechazado       { background: var(--state-danger-bg); color: var(--state-danger-text); }

.admin-message            { display: none; padding: var(--space-3) var(--space-4); border-radius: var(--radius-md); }
.admin-message.is-ok      { display: block; background: var(--state-ok-bg);     color: var(--state-ok-text);     border: 1px solid var(--state-ok-border); }
.admin-message.is-error   { display: block; background: var(--state-danger-bg); color: var(--state-danger-text); border: 1px solid var(--state-danger-border); }
```

### 15.4 IDs del DOM que el JS espera

El JavaScript busca elementos **por id**. Si la nueva organización rehace el HTML (debe hacerlo),
puede cambiar clases, estructura, orden y jerarquía libremente — pero **estos ids deben existir**,
o hay que actualizarlos en el JS de forma consistente.

**Página de donación**

```
donation-type-person / donation-type-company     Selector de tipo de aportante
donor-person-name / donor-person-document
donor-person-email / donor-person-phone
donor-company-name / donor-company-ruc
donor-company-email / donor-company-phone
donation-category        <select> nativo oculto (fuente de verdad del valor)
donation-category-trigger / -panel / -list        Dropdown accesible
donation-amounts         Contenedor de botones de monto
donation-custom-amount   Input de monto libre
donation-anonymous-check / donation-terms-check
donation-terms-modal / -open / -close / -accept
donation-mp-btn          CTA Mercado Pago
donation-qr-btn          CTA Yape/Plin
donation-back-btn        Volver del paso 2 al 1
donation-proof-file / -preview / -submit / -success
donation-proof-confirm-modal (+ -donor, -amount, -category, -file, -preview, -accept, -cancel, -close)
donation-proof-success-modal (+ -primary, -secondary, -close)
```

**Dashboard**

```
card-amount              Total recaudado
card-progress-fill       Barra de progreso (se anima su width)
pct-label                Porcentaje
donations-list           Feed de donantes
ticker                   Cinta de aportes recientes
top-category-label       Categoría líder
map-container            Mapa (opcional)
ranking-list             Ranking por categoría
```

**Panel admin**

```
donations-export-btn / donations-audit-btn
qr-refresh-btn / qr-status-filter / qr-table-body / qr-summary
qr-review-modal / -close / -cancel / -approve / -reject / -folio
qr-review-proof-wrap / -proof-img / -proof-empty / -proof-loading / -proof-link
qr-zoom-in / qr-zoom-out / qr-zoom-reset
qr-review-id / -nombre / -tipo / -categoria / -fecha / -estado / -declarado / -monto
cash-form / cash-id / cash-name / cash-document / cash-doc-label
cash-email / cash-phone / cash-category / cash-amount / cash-reference
cash-visible / cash-anonymous / cash-save-btn / cash-reset-btn
cash-table-body / cash-summary
admin-message
app-modal / -icon / -title / -body / -cancel / -confirm
btn-usuarios
```

> 💡 Alternativa más limpia que los ids: `data-*` (`[data-donation="amount"]`). Si la nueva
> organización lo prefiere, adelante — pero **cambia el JS entero de forma consistente** y actualiza
> esta tabla. Lo que no puede pasar es mezclar ambas convenciones.

### 15.5 Inventario de componentes a diseñar

| # | Componente | Tokens principales | Notas de diseño |
|---|---|---|---|
| 1 | **Hero de campaña** | `--brand-gradient`, `--text-inverse` | Titular + monto grande + barra |
| 2 | **Tarjeta de progreso** | `--brand-accent`, `--surface-card`, `--shadow-md` | Total / meta / % / restante; `role="progressbar"` con `aria-valuenow` |
| 3 | **Tira de estadísticas** | `--font-mono` para cifras | Donantes · categorías activas · promedio |
| 4 | **Ticker** | `--surface-sunken`, `--text-muted` | Marquesina de aportes; pausa en `hover`; respeta `prefers-reduced-motion` |
| 5 | **Formulario paso 1** | `--surface-card`, `--border-soft`, `--state-danger-*` | Dos columnas en escritorio, una en móvil |
| 6 | **Selector de montos** | `--brand-accent` en `.is-active` | Botones tipo *chip*; "Otro" revela input |
| 7 | **Dropdown de categorías** | `--surface-raised`, `--shadow-lg` | Accesible: flechas, typeahead, `Escape` |
| 8 | **Modal de términos** | `--surface-overlay` | Scroll interno; botón "Acepto" marca el checkbox |
| 9 | **Paso 2 — QR** | `--surface-sunken` | Dos tarjetas: "01 · Transferencia" / "02 · Comprobante" |
| 10 | **Dropzone de comprobante** | `--border-strong` punteado | Estados: vacío / `hover` / con archivo / error |
| 11 | **Modal de confirmación** | `--state-warn-*` | Resumen + miniatura antes de enviar |
| 12 | **Modal de éxito** | `--state-ok-*` | Mensaje + CTA a la campaña |
| 13 | **Feed de donantes** | `--surface-card` | Avatar con iniciales + nombre + categoría + fecha relativa + monto |
| 14 | **Ranking / mapa** | `--scale-0..4` | Escala secuencial de recaudación |
| 15 | **Login admin** | `--brand-primary` | Split: formulario + panel de marca |
| 16 | **Topbar admin** | `--brand-primary`, `--text-inverse` | Logo + navegación + salir |
| 17 | **Tarjetas de exportación** | `--surface-card` | Copy + *pills* de campos incluidos + CTA |
| 18 | **Tabla admin** | `--border-soft`, zebra con `--surface-sunken` | En móvil: scroll horizontal o *cards* apiladas |
| 19 | **Modal de revisión QR** | `--surface-overlay`, `--shadow-lg` | Dos columnas: visor + datos; en móvil apiladas |
| 20 | **Formulario de tesorería** | igual que el 5 | La etiqueta del documento cambia con persona/empresa |

### 15.6 Reglas de diseño no negociables

Sobre estas, la marca es libre. Estas se respetan porque afectan a la **usabilidad y la confianza**
en un flujo donde hay dinero de por medio:

1. **Contraste AA mínimo** (4.5:1 texto normal, 3:1 texto grande). Un botón de donar ilegible cuesta
   dinero literal.
2. **Objetivos táctiles ≥ 44 × 44 px** en móvil. La mayoría de donantes llega desde el teléfono.
3. **Foco visible siempre.** Nunca `outline: none` sin reemplazo:
   ```css
   :focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
   ```
4. **Un solo CTA primario por pantalla.** "Donar con Mercado Pago" es primario; "Yape/Plin" es
   secundario (o al revés, pero no ambos primarios).
5. **El monto siempre visible** en el paso 2. El donante debe transferir la cifra exacta.
6. **Estados de error junto al campo**, no solo arriba. Con `aria-describedby`.
7. **Botón en estado de carga** durante el checkout, con texto ("Conectando con Mercado Pago…").
   Sin esto, la gente pulsa dos veces y se crean donaciones duplicadas.
8. **Nada de colores como único portador de información.** El estado "aprobado" lleva texto o icono,
   no solo verde.
9. **Sin *layout shift*** cuando llegan los datos del dashboard: reserva altura con *skeletons*.
10. **Sello de confianza visible**: logos de medios de pago, "pago seguro", datos de contacto de la
    organización. Aumenta la conversión de forma medible.

### 15.7 Checklist de re-skin

```
[ ] Definir la paleta en css/tokens.css (incluye modo oscuro si aplica)
[ ] Cargar las tipografías de la marca (self-hosted preferible: más rápido y sin terceros)
[ ] Reemplazar logo, favicon y og:image
[ ] Sustituir img/donaciones/qr.png por el QR real de la organización
[ ] Verificar contraste AA de todos los estados semánticos
[ ] Reescribir el copy: hero, pasos, términos, mensajes de error, correos
[ ] Ajustar montos sugeridos y meta (DONATION_GOAL)
[ ] Traducir la taxonomía: categorías propias en config/categories.php y en el dropdown
[ ] Revisar que ningún .js contenga un valor hex o rgb()
[ ] Probar en 360 px, 768 px, 1440 px
[ ] Probar con prefers-reduced-motion y prefers-color-scheme: dark
[ ] Recorrer todo el flujo solo con teclado (Tab / Enter / Escape / flechas)
[ ] Lighthouse: Performance ≥ 85, Accessibility ≥ 95 en la página de donación
```

### 15.8 Contenido legal que la organización debe redactar

No es diseño, pero bloquea el lanzamiento — resuélvelo temprano:

- **Términos y condiciones de la donación** (el modal del checkbox).
- **Política de privacidad**: qué datos se recogen, para qué, cuánto se conservan, cómo se ejerce
  el derecho de supresión. En Perú, la Ley 29733 exige informar el tratamiento de datos personales.
- **Política de devoluciones**: qué pasa si alguien dona por error.
- **Identificación de la entidad receptora**: razón social, RUC, dirección, contacto. La gente no
  dona a un sitio anónimo.
- **Aviso de comprobante**: si se emite recibo por donación y con qué condiciones.

---

## 16. Seguridad

### 16.1 `.htaccess` de la raíz

```apache
# ── 1. Sin listados de directorio ────────────────────────────────────────────
Options -Indexes -ExecCGI -Includes

# ── 2. Archivos ocultos y sensibles ──────────────────────────────────────────
<FilesMatch "^\.">
    Require all denied
</FilesMatch>

<FilesMatch "(composer\.(json|lock)|package(-lock)?\.json|README\.md|.*\.(sql|log|md|ini|bak|old))$">
    Require all denied
</FilesMatch>

# ── 3. HTTPS obligatorio en producción ───────────────────────────────────────
<IfModule mod_rewrite.c>
    RewriteEngine On

    # En local, nunca fuerces HTTPS (los certificados autofirmados molestan)
    RewriteCond %{HTTP_HOST} ^(localhost|127\.0\.0\.1)(:\d+)?$ [NC]
    RewriteCond %{HTTPS} on
    RewriteRule ^(.*)$ http://%{HTTP_HOST}%{REQUEST_URI} [L,R=302]

    RewriteCond %{HTTP_HOST} ^(www\.)?midominio\.org$ [NC]
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # El panel admin solo por su wrapper PHP
    RewriteRule ^panel_admin\.html$    panel_admin.php    [L,R=302,NC]
    RewriteRule ^admin_usuarios\.html$ admin_usuarios.php [L,R=302,NC]
</IfModule>

# ── 4. Cabeceras de seguridad ────────────────────────────────────────────────
<IfModule mod_headers.c>
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-Content-Type-Options "nosniff"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
    Header set Permissions-Policy "geolocation=(), microphone=(), camera=()"
    # Activa HSTS solo cuando el HTTPS esté 100 % estable:
    # Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"

    # Sin caché en las rutas críticas del flujo de donación
    SetEnvIfNoCase Request_URI "(^|/)donaciones(\.html)?$"        NO_CACHE=1
    SetEnvIfNoCase Request_URI "(^|/)api/donors/list\.php$"       NO_CACHE=1
    SetEnvIfNoCase Request_URI "(^|/)panel_admin(\.php|\.html)?$" NO_CACHE=1
    SetEnvIfNoCase Request_URI "(^|/)js/modules/.*\.js$"          NO_CACHE=1

    Header always set Cache-Control "no-store, no-cache, must-revalidate, max-age=0" env=NO_CACHE
    Header always set Pragma "no-cache" env=NO_CACHE
    Header always set Expires "0" env=NO_CACHE
</IfModule>

# ── 5. UTF-8 en todo ─────────────────────────────────────────────────────────
AddDefaultCharset UTF-8
<IfModule mod_mime.c>
    AddCharset UTF-8 .html .css .js .json .txt .xml
</IfModule>
```

### 16.2 `api/.htaccess`

```apache
Options -Indexes

# Fija el archivo de entorno activo (útil para alternar local/producción)
# SetEnv APP_ENV_FILE .env2

<FilesMatch "\.(env|env\..*|ini|log|sql|md|lock|bak)$">
    Require all denied
</FilesMatch>

<IfModule mod_headers.c>
    Header always set X-Frame-Options "DENY"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Cache-Control "no-store, no-cache, must-revalidate, max-age=0"
</IfModule>

AddDefaultCharset UTF-8
```

Y además: `api/logs/.htaccess` y `api/uploads/.htaccess` con `Require all denied` (ver Sección 8.3).

> ⚠️ **Verifica que funcionan.** En Nginx o con `AllowOverride None`, el `.htaccess` se ignora
> **en silencio**. Después de desplegar, abre `https://midominio.org/api/.env` en el navegador:
> debe dar 403. Si descarga el archivo, tienes una emergencia.

### 16.3 Checklist de seguridad antes de lanzar

**Secretos**
```
[ ] .env fuera de git y con 403 comprobado por HTTP
[ ] MP_ACCESS_TOKEN de producción nunca estuvo en un commit (si estuvo: rótalo)
[ ] Contraseña del superadmin ≥ 16 caracteres, única, en un gestor de contraseñas
[ ] api/create_admin.php borrado del servidor
[ ] Sin hashes de contraseña hardcodeados en schema.sql
[ ] Ningún archivo _check.php / debug_*.php / test_*.php en producción
```

**Base de datos**
```
[ ] Usuario MySQL dedicado, solo con permisos sobre esta base (no root)
[ ] PDO::ATTR_EMULATE_PREPARES => false
[ ] 100 % de las consultas con parámetros (cero concatenación de input)
[ ] Backup automático diario + restauración probada al menos una vez
```

**Sesiones**
```
[ ] Nombre de cookie único por organización
[ ] httponly=true, secure=true en producción, samesite=Lax
[ ] session_regenerate_id(true) tras el login
[ ] use_strict_mode=1
```

**Entradas**
```
[ ] Toda validación replicada en el servidor (el front es solo UX)
[ ] Subidas: MIME por finfo, límite de tamaño, nombre aleatorio, SVG prohibido
[ ] escapeHtml() en todo dato de la API que se inserte con innerHTML
[ ] CORS restringido al dominio real (sin '*' con credentials)
```

**Datos personales**
```
[ ] donors/list.php NO expone documento, correo, teléfono ni IP
[ ] Comprobantes servidos solo a admins autenticados (o carpeta sin ejecución)
[ ] El ZIP de auditoría solo lo genera superadmin, y se borra del servidor al enviarse
[ ] Política de privacidad publicada y enlazada desde el formulario
[ ] Política de retención definida (p. ej. comprobantes: 5 años por normativa contable)
```

**Pagos**
```
[ ] APP_ENV=production y credenciales APP_USR- en producción
[ ] Webhook apuntando a la URL HTTPS real y probado end-to-end
[ ] Ningún pago con live_mode=no en los datos de producción
[ ] Reconciliación probada: pagar, cerrar el navegador antes de volver, verificar el estado
```

### 16.4 Qué endurecer si la campaña crece

| Riesgo | Mitigación |
|---|---|
| Fuerza bruta en el login | Tabla de intentos + 429 tras 5 fallos en 15 min |
| Spam de donaciones falsas (llenan la BD) | Rate-limit por IP en `create.php` (p. ej. 10/hora) + honeypot en el formulario |
| Subida masiva de comprobantes | Rate-limit + cuota por IP/día en `qr_submit.php` |
| Webhook falsificado | Validar la firma `x-signature` de MP (Sección 7.3) |
| Enumeración de donaciones vía `reconcile.php` | Rate-limit; el endpoint no filtra datos personales, pero limita el ruido |
| Fuga de comprobantes | Servirlos por endpoint autenticado, no por URL pública |
| CSRF en el panel admin | `SameSite=Lax` cubre lo básico; añade un token CSRF si algún endpoint admin pasa a ser GET con efectos |

---

## 17. Despliegue

### 17.1 Local (XAMPP / Laragon)

```bash
# 1) Copia el proyecto en el document root
#    C:\xampp\htdocs\mi_proyecto

# 2) Dependencias PHP
cd C:\xampp\htdocs\mi_proyecto\api
composer install

# 3) Configuración
copy .env.example .env      # y edítalo con las credenciales de sandbox

# 4) Base de datos
php bootstrap_db.php

# 5) Primer administrador
php create_admin.php admin "ClaveLocalSegura2026" superadmin

# 6) Verifica
#    http://localhost/mi_proyecto/donaciones.html
#    http://localhost/mi_proyecto/api/donors/list.php   → JSON con success:true
```

### 17.2 Webhooks en local con ngrok

Mercado Pago necesita una URL pública para notificar. En desarrollo:

```bash
ngrok http 80
# → Forwarding  https://abc123.ngrok-free.app -> http://localhost:80
```

En `api/.env`:

```env
MP_WEBHOOK_URL=https://abc123.ngrok-free.app/mi_proyecto/api/donation/webhook.php
```

> ⚠️ La URL de ngrok **cambia en cada reinicio** (en el plan gratuito). Actualiza el `.env` cada
> vez. Y ojo: la página interstitial de ngrok puede interferir con las `back_urls` — por eso
> conviene que `MP_SUCCESS_URL` apunte a un dominio HTTPS estable, no a ngrok.

### 17.3 Producción en cPanel (hosting compartido)

```
1.  PHP 8.2+ en "Select PHP Version"; habilita curl, mbstring, openssl,
    pdo_mysql, fileinfo, zip
2.  Sube el proyecto al document root (public_html o el del subdominio)
3.  Crea la base de datos y un usuario dedicado en "MySQL Databases"
4.  Sube api/vendor/ por FTP si el hosting no tiene Composer
    (o ejecútalo en local y sube la carpeta completa)
5.  Crea api/.env con los valores de producción (nunca lo subas desde git)
6.  Permisos:
       api/logs           755  (escritura del usuario web)
       api/uploads/proofs 755
       api/tmp            755
       api/.env           600
7.  Importa schema.sql por phpMyAdmin, o ejecuta bootstrap_db.php
    por SSH / "Terminal"
8.  Crea el superadmin por terminal y BORRA create_admin.php
9.  Certificado SSL (AutoSSL / Let's Encrypt) y verifica el candado
10. Registra el webhook de producción en el panel de Mercado Pago
11. Prueba con una donación real de S/ 5 y verifica los tres canales
```

### 17.4 Variables por entorno

| Variable | Local | Producción |
|---|---|---|
| `APP_ENV` | `sandbox` | `production` |
| `APP_URL` | `http://localhost/mi_proyecto` | `https://midominio.org` |
| `MP_*` | credenciales TEST | credenciales APP_USR |
| `MP_WEBHOOK_URL` | URL de ngrok | URL HTTPS real |
| `MP_USE_SANDBOX_INIT_POINT` | `true` | `false` |
| `DASHBOARD_INCLUDE_PENDING` | `true` (para ver movimiento) | `false` |
| `DB_*` | root / sin contraseña | usuario dedicado + contraseña fuerte |

### 17.5 Copias de seguridad

```bash
# Diario, por cron: base de datos comprimida
mysqldump -u USUARIO -p'CLAVE' BASE | gzip > /home/usuario/backups/db-$(date +\%F).sql.gz

# Semanal: comprobantes
tar -czf /home/usuario/backups/proofs-$(date +\%F).tar.gz api/uploads/proofs

# Retención: 30 días
find /home/usuario/backups -name '*.gz' -mtime +30 -delete
```

> ⚠️ Un backup que nunca se restauró **no es un backup**. Prueba la restauración completa en local
> al menos una vez antes de lanzar la campaña.

---

## 18. Plan de pruebas (QA)

### 18.1 Mercado Pago

| # | Caso | Pasos | Resultado esperado |
|---|---|---|---|
| 1 | Pago aprobado | Donar con titular `APRO` | `estado=aprobado`, `mp_payment_id`, `monto_real` = monto |
| 2 | Pago rechazado | Titular `OTHE` | `estado=rechazado`; no suma al total |
| 3 | Pago pendiente | Titular `CONT` | `estado=en_proceso` |
| 4 | Webhook caído | Apaga la URL del webhook, paga, vuelve al sitio | `reconcile.php` actualiza igual el estado |
| 5 | Usuario cierra el navegador | Paga y cierra antes de volver | El webhook lo resuelve igual |
| 6 | Ambos fallan | Sin webhook y sin volver | La auditoría lo marca como `RESCATADO` |
| 7 | Doble clic en Donar | Pulsar rápido dos veces | El botón queda deshabilitado; una sola preferencia |
| 8 | MP caído | Token inválido a propósito | 502 con mensaje amable; **sin** donación huérfana en la BD |
| 9 | Monto bajo el mínimo | Enviar 1 | 422 con el mensaje del mínimo |
| 10 | Categoría inventada | POST manual con `"categoria":"Marte"` | 422: categoría inválida |

### 18.2 Canal QR

| # | Caso | Resultado esperado |
|---|---|---|
| 11 | Subida válida (JPG) | 201, archivo en `uploads/proofs/AAAA/MM/`, fila `pendiente` |
| 12 | PDF | Aceptado |
| 13 | Archivo de 15 MB | 422, no se guarda nada |
| 14 | `.exe` renombrado a `.jpg` | 422 (el MIME real no es de imagen) |
| 15 | SVG | 422 |
| 16 | Sin archivo | 422 |
| 17 | Fallo de BD tras guardar el archivo | El archivo se borra (sin huérfanos) |
| 18 | Admin aprueba con monto distinto | `monto_real` = lo que puso el admin, no lo declarado |
| 19 | Admin rechaza | `estado=rechazado`; desaparece del total |
| 20 | Aprobar una donación que no es QR | 404 (el endpoint filtra por canal) |

### 18.3 Administración

| # | Caso | Resultado esperado |
|---|---|---|
| 21 | `GET api/admin/qr/list.php` sin sesión | 401 |
| 22 | `panel_admin.html` sin sesión | Redirige al login |
| 23 | Editor entra a `admin_usuarios.php` | 403 |
| 24 | Editor descarga el ZIP de auditoría | 403 (solo superadmin) |
| 25 | Eliminar al último superadmin | 409 |
| 26 | Quitarse a sí mismo el rol de superadmin | 403 |
| 27 | Tesorería: DNI de 7 dígitos | 422 |
| 28 | Tesorería: RUC de 11 con tipo "empresa" | OK, se guarda `aprobado` |
| 29 | Export Excel | Se descarga; tildes correctas; DNI conserva ceros |
| 30 | ZIP de auditoría | Contiene los 9 CSV y `00_metadata.json` |

### 18.4 Público y datos

| # | Caso | Resultado esperado |
|---|---|---|
| 31 | Donante anónimo | Aparece como "Donante Anónimo (MP)" |
| 32 | `donors/list.php` | **No** contiene documento, correo, teléfono ni IP |
| 33 | Nombre con `<script>` | Se muestra escapado, no se ejecuta |
| 34 | Redistribución de "General" | La suma de categorías = total general (±0.01) |
| 35 | Barra de progreso | Nunca supera el 100 %, ni con exceso de meta |
| 36 | Sin donaciones | El dashboard muestra ceros, sin errores de consola |
| 37 | API caída | La página carga igual; no hay pantalla en blanco |
| 38 | Móvil 360 px | Sin scroll horizontal; botones ≥ 44 px |
| 39 | Solo teclado | Se completa todo el flujo; el foco siempre visible |
| 40 | Refresco cada 30 s | Los números se actualizan sin parpadeo molesto |

### 18.5 Consultas SQL de verificación

```sql
-- ¿Pagos aprobados en MP que la BD tiene como pendientes?
SELECT id, nombre, estado, mp_payment_id, monto_referencial, monto_real, created_at
FROM donaciones
WHERE canal_pago = 'mercadopago' AND estado = 'pendiente' AND mp_payment_id IS NOT NULL;

-- ¿Cuadra el total por canal?
SELECT canal_pago, estado, COUNT(*) AS n,
       SUM(COALESCE(monto_real, monto_referencial)) AS total
FROM donaciones GROUP BY canal_pago, estado ORDER BY canal_pago, estado;

-- Webhooks que fallaron
SELECT id, evento, status, procesado, created_at
FROM webhook_logs WHERE procesado = 0 ORDER BY id DESC LIMIT 50;

-- Donaciones QR sin comprobante (no debería haber ninguna)
SELECT id, nombre, created_at FROM donaciones
WHERE canal_pago = 'qr_manual' AND (comprobante_path IS NULL OR comprobante_path = '');

-- Posibles duplicados (mismo donante, mismo monto, en 5 minutos)
SELECT a.id, b.id, a.documento, a.monto_referencial, a.created_at
FROM donaciones a
JOIN donaciones b
  ON a.documento = b.documento AND a.monto_referencial = b.monto_referencial
 AND a.id < b.id
 AND TIMESTAMPDIFF(MINUTE, a.created_at, b.created_at) BETWEEN 0 AND 5;
```

---

## 19. Troubleshooting: errores reales y su solución

Problemas que ocurrieron de verdad en el sistema original, y su causa raíz.

### Mercado Pago

**"Algo salió mal" al abrir el checkout**
Mezcla de credenciales: `init_point` con credenciales `TEST-`, o `sandbox_init_point` con
`APP_USR-`. Revisa `MP_USE_SANDBOX_INIT_POINT` y que **public key y access token sean de la misma
aplicación**. El campo `checkout_point_used` de la respuesta te dice cuál se usó.

**Error 400 al crear la preferencia, mensaje `auto_return invalid`**
`back_urls.success` no es HTTPS. Mercado Pago exige HTTPS para `auto_return`. El código ya lo
contempla: solo añade `auto_return` si la URL empieza por `https://`.

**El pago se aprueba pero la BD sigue en `pendiente`**
El webhook no llegó. Diagnóstico en orden:
1. `SELECT * FROM webhook_logs ORDER BY id DESC LIMIT 10;` — ¿hay filas?
2. Si no hay ninguna → la URL del webhook es incorrecta o inaccesible. Pruébala con
   `curl -X POST https://midominio.org/api/donation/webhook.php` (debe responder JSON).
3. Si hay filas con `status='mp_error'` → el access token no es el de la cuenta que cobró.
4. Mientras tanto, `reconcile.php` cubre el hueco.

**El pagador no puede pagar en sandbox**
Estás enviando `payer.email` real. En sandbox solo funcionan cuentas de prueba
(`@testuser.com`). Deja `MP_SANDBOX_FORCE_PAYER_TEST=false` y no mandes `payer`.

**El DNI de prueba es rechazado**
El checkout de sandbox a veces limita el DNI a 8 dígitos. Usa `12345678`, o cambia
`MP_SANDBOX_PAYER_DOC_TYPE` a otro tipo, o no envíes `identification`.

**Llegan webhooks `merchant_order` sin pagos**
Normal: MP notifica la orden antes de asociar el pago. El código reconsulta tras 800 ms y, si sigue
vacía, responde 200 e ignora. No es un error.

### Base de datos

**`SQLSTATE[HY000] [1045] Access denied`**
Credenciales de `.env` incorrectas, o estás leyendo el `.env` equivocado. Comprueba cuál está
activo: `var_dump(getenv('APP_ENV_FILE'));`.

**Tildes convertidas en `Ã±` / `Ã©`**
Falta `charset=utf8mb4` en el DSN, o la tabla no es `utf8mb4_unicode_ci`, o falta la cabecera
`Content-Type: application/json; charset=utf-8`. Verifica los tres.

**`Unknown column 'categoria'`**
`app_db_ensure_schema()` no se llamó antes de la consulta. Añádelo justo después de `app_db()`.

### Subidas

**"No se pudo guardar el comprobante"**
Permisos de `api/uploads/proofs`. Debe ser escribible por el usuario de Apache/PHP (755 con el
propietario correcto; 775 si el grupo coincide).

**Archivos grandes fallan en silencio**
`upload_max_filesize` y `post_max_size` en `php.ini` por debajo de 10 MB. Súbelos a 12M y reinicia
Apache. Si no puedes tocar `php.ini`, hazlo por `.htaccess`:
`php_value upload_max_filesize 12M`.

**El comprobante no se ve en el panel**
`comprobante_url` está mal armada. Comprueba `APP_URL` en el `.env` e imprime el valor que llega al
`<img>`. Recuerda que la ruta en la BD es relativa a `api/`.

### Sesiones y panel

**El panel redirige al login en bucle**
Falta `credentials: 'include'` en el `fetch`, o el dominio del front y el de la API difieren
(cookie de otro *origin*), o `secure=true` sobre HTTP.

**El panel se congela al generar la auditoría**
Falta `session_write_close()` al inicio de `auditoria.php`. Sin eso, PHP bloquea el archivo de
sesión y **todas** las demás peticiones del panel quedan en cola.

**Aparece un 401 al cabo de un rato**
Es correcto: la cookie tiene `lifetime = 0` y el recolector de basura de sesiones limpia las
inactivas. Si la organización quiere sesiones más largas, súbelo de forma explícita — no dejes la
sesión eterna.

### Frontend

**El dashboard no carga en el servidor de desarrollo**
`resolveApiBase()` no cubre tu caso. Define `window.__API_BASE__` a mano en esa página y sigue.

**CORS bloqueado**
El `Origin` del navegador no coincide con `APP_URL`. Revisa `resolveAllowedOrigin()` en
`Response.php` y añade el origen a la lista blanca.

**Las cifras "saltan" en cada refresco**
Estás reanimando los contadores cada 30 s. Anima solo la primera carga; después, asigna el valor.

**El checkout no abre en un navegador embebido**
Restricciones de WebView. Usa `window.open(..., '_blank', 'noopener,noreferrer')` y muestra el
enlace como texto por si el bloqueador de ventanas lo impide.

---

## 20. Orden de implementación recomendado

Seis fases. Cada una tiene un **criterio de aceptación verificable**: no pases a la siguiente sin
cumplirlo.

### Fase 1 — Cimientos (medio día)

```
1. Estructura de carpetas
2. api/composer.json + composer install
3. config/env.php, config/database.php, config/categories.php
4. utils/Response.php, Validator.php, Logger.php
5. sql/schema.sql + bootstrap_db.php
6. .htaccess de raíz y de api/
```
✅ **Aceptación:** `php api/bootstrap_db.php` crea las tablas, y
`http://localhost/proyecto/api/.env` devuelve 403.

### Fase 2 — Canal Mercado Pago (1 día)

```
7.  config/mercadopago.php
8.  donation/create.php
9.  donation/webhook.php
10. donation/reconcile.php
11. ngrok + registro del webhook en el panel de MP
```
✅ **Aceptación:** una donación de prueba con titular `APRO` termina en `estado=aprobado` con
`mp_payment_id` y `monto_real` poblados **sin intervención manual**.

### Fase 3 — Datos públicos y frontend mínimo (1 día)

```
12. donors/list.php
13. donaciones.html con la marca nueva (paso 1)
14. js/modules/donation-flow.js (crear + redirigir + reconciliar)
15. js/modules/dashboard.js (métricas en vivo)
16. css/tokens.css + CSS de los componentes
```
✅ **Aceptación:** se dona desde la web con la marca aplicada, y al volver del checkout la barra de
progreso refleja el aporte.

### Fase 4 — Canal QR (medio día)

```
17. donation/qr_submit.php + uploads/.htaccess
18. Paso 2 del formulario: QR, dropzone, modales de confirmación y éxito
```
✅ **Aceptación:** se sube un comprobante, queda `pendiente` con el archivo guardado, y un `.exe`
renombrado a `.jpg` es rechazado.

### Fase 5 — Administración (1–2 días)

```
19. admin/auth.php, login, logout, check_auth
20. admin_login.html + panel_admin.html (+ wrappers PHP)
21. admin/qr/list.php y verify.php + modal de revisión con zoom
22. admin/cash/list.php y save.php + formulario de tesorería
23. admin/users.php + admin_usuarios.html
24. admin/donations/export.php
25. create_admin.php (ejecutar y borrar)
```
✅ **Aceptación:** un admin aprueba un comprobante QR con un monto distinto al declarado y el
dashboard público refleja el monto correcto en menos de 30 s.

### Fase 6 — Auditoría y endurecimiento (1 día)

```
26. admin/donations/auditoria.php
27. Checklist de seguridad de la Sección 16.3
28. Batería de pruebas de la Sección 18
29. Backups automáticos
30. Paso a producción: credenciales APP_USR, APP_ENV=production, webhook real
```
✅ **Aceptación:** el ZIP de auditoría descarga con los 9 CSV, `07_maestro.csv` cuadra con la BD, y
una donación real de S/ 5 recorre el flujo completo en producción.

---

## Apéndice A — Referencia rápida de la API

| Método | Endpoint | Auth | Entrada | Salida |
|---|---|---|---|---|
| POST | `/api/donation/create.php` | — | JSON de donación | `init_point`, `preference_id`, `donation_id` |
| POST/GET | `/api/donation/webhook.php` | — (MP) | Notificación de MP | `{success:true}` |
| POST/GET | `/api/donation/reconcile.php` | — | `donation_id` \| `preference_id` \| `payment_id` | `reconciled`, `status` |
| POST | `/api/donation/qr_submit.php` | — | multipart + `proof_file` | `donation_id`, `proof_path` |
| GET | `/api/donors/list.php` | — | — | Totales, categorías, donantes |
| POST | `/api/admin/login.php` | — | `username`, `password` | `user{username, role}` |
| POST | `/api/admin/logout.php` | sesión | — | `message` |
| GET | `/api/admin/check_auth.php` | — | — | `authenticated`, `user` |
| GET/POST/PUT/DELETE | `/api/admin/users.php` | superadmin | JSON | `usuarios` / `message` |
| GET | `/api/admin/qr/list.php` | sesión | `?state=` | `items[]` |
| POST | `/api/admin/qr/verify.php` | sesión | `donation_id`, `estado`, `monto_real?` | `item` |
| GET | `/api/admin/cash/list.php` | sesión | — | `items[]`, `categorias_catalogo` |
| POST | `/api/admin/cash/save.php` | sesión | JSON (con `id` = editar) | `item` |
| GET | `/api/admin/donations/export.php` | sesión | — | archivo `.xls` |
| GET | `/api/admin/donations/auditoria.php` | superadmin | flags opcionales | archivo `.zip` |

**Forma de respuesta (siempre la misma):**
```json
{ "success": true,  "…datos…" }
{ "success": false, "error": "Mensaje para el usuario", "errors": ["detalle 1", "detalle 2"] }
```

**Códigos de estado:** `200` OK · `201` creado · `204` preflight OPTIONS · `400` JSON inválido ·
`401` sin sesión · `403` sin permisos · `404` no encontrado · `405` método · `409` conflicto ·
`422` validación · `500` interno · `502` pasarela caída.

---

## Apéndice B — Glosario de campos de Mercado Pago

| Campo | Qué es |
|---|---|
| `external_reference` | **Tu** identificador. Aquí: el `id` de la donación. La llave de todo el sistema |
| `preference_id` | ID de la intención de pago (se crea antes de pagar) |
| `payment_id` | ID del pago real (existe solo después de pagar) |
| `merchant_order` | Agrupador: una orden puede contener varios intentos de pago |
| `status` | `approved` · `pending` · `in_process` · `rejected` · `cancelled` · `refunded` · `charged_back` |
| `status_detail` | Motivo detallado (`cc_rejected_insufficient_amount`, `accredited`, …) |
| `transaction_amount` | Monto bruto que pagó el donante |
| `transaction_details.net_received_amount` | **Lo que realmente recibe la organización** |
| `transaction_details.total_paid_amount` | Total pagado incluyendo intereses de cuotas |
| `fee_details[]` | Comisiones: `{type, amount, fee_payer}` |
| `fee_details[].fee_payer` | `collector` (lo pagas tú) o `payer` (lo paga el donante) |
| `money_release_date` | Cuándo estará disponible el dinero para retirar |
| `money_release_status` | Estado de la liberación |
| `live_mode` | `true` = dinero real · `false` = prueba |
| `transaction_amount_refunded` | Monto devuelto, si hubo reembolso |
| `payment_method_id` | `visa`, `master`, `pagoefectivo_atm`, `yape`, … |
| `payment_type_id` | `credit_card`, `debit_card`, `ticket`, `bank_transfer`, `account_money` |

---

## Apéndice C — Deudas técnicas del sistema original

Cosas que en el proyecto de referencia funcionan, pero que **conviene no copiar tal cual**. Están
listadas para que la nueva implementación nazca más limpia:

| # | Deuda | Cómo hacerlo bien |
|---|---|---|
| 1 | Hash del superadmin dentro de `schema.sql` | Script CLI `create_admin.php` (Sección 11.5) |
| 2 | CORS con fallback `'*'` junto a `credentials: true` | Lista blanca explícita (Sección 6.1) |
| 3 | Colores hex dentro del JavaScript | Clases de estado + tokens CSS (Sección 15.1) |
| 4 | Tokens de diseño en un `<style>` del HTML admin | `css/tokens.css` |
| 5 | Comprobantes en carpeta pública | Endpoint autenticado (Sección 8.3, opción A) |
| 6 | `DASHBOARD_INCLUDE_PENDING` declarado pero no usado | Implementarlo de verdad (Sección 4.3) |
| 7 | Estados pendientes sumando al total público | Decisión explícita y documentada |
| 8 | Sin registro de qué admin registró cada monto | Columna `registrado_por` |
| 9 | Sin validación de la firma del webhook | Validar `x-signature` |
| 10 | Sin rate-limit en endpoints públicos | Límite por IP en `create.php` y `qr_submit.php` |
| 11 | Referencias de lote anónimo hardcodeadas en el código | Columna o flag `es_lote_anonimo` |
| 12 | Archivos `debug_*.php` / `check_*.php` en la raíz de `api/` | Fuera de producción, o borrados |

---

## Cierre

Con este documento, la nueva organización puede tener:

- **Backend idéntico al que ya está probado con dinero real** — el mismo modelo de datos, los mismos
  contratos, las mismas redes de seguridad.
- **Identidad visual completamente propia** — paleta, tipografía, layout y copy, sin tocar una línea
  de lógica.

Si algo se debe recordar de todo esto, que sean tres cosas:

1. **`external_reference = id` de la donación.** Es lo que permite recuperar cada sol.
2. **Tres redes de seguridad** (webhook, reconciliación, rescate en auditoría). Ninguna sola basta.
3. **`monto_real` es sagrado.** Es lo que de verdad entró, y es lo único que se rinde en cuentas.
