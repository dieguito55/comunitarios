# Despliegue automático de comunitarios.org

El workflow `.github/workflows/deploy.yml` se ejecuta con cada `push` a `main` y también se puede iniciar manualmente desde **Actions**.

## Estructura en el hosting

- `/home/comuonal/public_html`: archivos públicos servidos por `https://comunitarios.org`.
- `/home/comuonal/comunitarios_app`: Laravel, dependencias, configuración privada y las últimas tres versiones.
- `/home/comuonal/comunitarios_app/shared/.env`: configuración de producción persistente.
- `/home/comuonal/comunitarios_app/shared/storage`: logs, caché y sesiones persistentes.

Nunca se publica el `.env` local ni se expone `vendor` dentro de `public_html`.

## Secrets requeridos en GitHub

Crear estos secrets en **Settings > Secrets and variables > Actions > New repository secret**:

| Secret | Valor |
| --- | --- |
| `SSH_HOST` | `premium315.web-hosting.com` |
| `SSH_PORT` | `21098` |
| `SSH_USER` | `comuonal` |
| `SSH_PRIVATE_KEY` | Contenido completo de la llave SSH privada dedicada al despliegue |
| `SSH_KNOWN_HOSTS` | Salida verificada de `ssh-keyscan -p 21098 premium315.web-hosting.com` |
| `PRODUCTION_ENV` | Contenido completo del `.env` de producción descrito abajo |

## Plantilla para PRODUCTION_ENV

Generar una clave con `php artisan key:generate --show` y reemplazar `PEGAR_APP_KEY_GENERADA`.

```dotenv
APP_NAME="Comunitarios"
APP_ENV=production
APP_KEY=PEGAR_APP_KEY_GENERADA
APP_DEBUG=false
APP_URL=https://comunitarios.org

APP_LOCALE=es
APP_FALLBACK_LOCALE=es
APP_FAKER_LOCALE=es_PE
APP_MAINTENANCE_DRIVER=file

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=sqlite

SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
CACHE_STORE=file

MAIL_MAILER=log
```

## Requisitos del hosting

- SSH habilitado para la cuenta `comuonal`.
- PHP CLI 8.3 o superior.
- La llave pública correspondiente a `SSH_PRIVATE_KEY` importada y autorizada en cPanel.
- El dominio principal apuntando a `/home/comuonal/public_html`.

## Funcionamiento

1. GitHub instala dependencias de PHP y JavaScript.
2. Ejecuta la compilación de Vite y las pruebas de Laravel.
3. Sube una versión nueva a una carpeta identificada por el commit.
4. Conserva `.env` y `storage` fuera de las versiones.
5. Optimiza Laravel y cambia la versión activa de forma atómica.
6. Sincroniza `public_html` y comprueba que el dominio responda correctamente.
7. Conserva las tres versiones más recientes para no llenar el disco.
