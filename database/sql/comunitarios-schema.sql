-- =============================================================================
-- comunitarios.org - esquema de base de datos
--
--   Version   : fase-4  (sitio publico y flujo de donacion)
--   Generado  : 2026-09-27
--   Motor     : MariaDB 10.4 / MySQL 5.7+ - InnoDB - utf8mb4_unicode_ci
--
-- QUE CONTIENE
--   - Estructura de las 14 tablas, sin datos
--   - La vista v_fondos_conciliacion, SIN DEFINER y con SQL SECURITY INVOKER
--   - La tabla `migrations` ya rellena con las 14 aplicadas, para que Laravel
--     no vuelva a ejecutarlas
--   - El fondo "Fundacion Antonia", con los contadores a cero
--
-- QUE NO CONTIENE, A PROPOSITO
--   - Ningun usuario administrador ni ningun hash de contrasena.
--     El superadmin se crea con `php artisan make:superadmin` por SSH o por
--     el Terminal de cPanel. Un hash dentro de un .sql acaba compartido por
--     correo y reutilizado entre instalaciones.
--   - Ninguna sentencia CREATE DATABASE ni USE: en cPanel la base ya existe
--     y se llama <usuario>_comunitarios.
--   - Ningun DEFINER: en hosting compartido el usuario del volcado no existe
--     y el import falla con "access denied; you need SUPER privileges".
--   - Ningun AUTO_INCREMENT heredado: las tablas empiezan en 1.
--
-- DESPUES DE IMPORTAR ESTE ARCHIVO, EJECUTA:
--
--   php artisan key:generate --force
--   php artisan config:cache && php artisan route:cache
--   php artisan make:superadmin
--
-- =============================================================================

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `admin_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'editor',
  `last_login` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_users_username_unique` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `donaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `donaciones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) NOT NULL,
  `documento` varchar(20) NOT NULL,
  `correo` varchar(200) NOT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `tipo_aportante` varchar(20) NOT NULL DEFAULT 'persona',
  `fondo_id` bigint(20) unsigned NOT NULL,
  `monto_referencial` decimal(10,2) NOT NULL,
  `monto_real` decimal(10,2) DEFAULT NULL,
  `moneda` char(3) NOT NULL DEFAULT 'PEN',
  `canal_pago` varchar(20) NOT NULL DEFAULT 'mercadopago',
  `proveedor_pago` varchar(30) NOT NULL DEFAULT 'mercadopago',
  `referencia_pago` varchar(120) DEFAULT NULL,
  `comprobante_path` varchar(255) DEFAULT NULL,
  `comprobante_mime` varchar(100) DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
  `mp_preference_id` varchar(200) DEFAULT NULL,
  `mp_payment_id` varchar(200) DEFAULT NULL,
  `mp_status_detail` varchar(80) DEFAULT NULL,
  `mp_payment_method_id` varchar(40) DEFAULT NULL,
  `mp_payment_type_id` varchar(40) DEFAULT NULL,
  `mp_fee` decimal(10,2) DEFAULT NULL,
  `mp_net_received` decimal(10,2) DEFAULT NULL,
  `mp_date_approved` timestamp NULL DEFAULT NULL,
  `mp_date_last_updated` timestamp NULL DEFAULT NULL,
  `mp_live_mode` tinyint(1) DEFAULT NULL,
  `visible_publico` tinyint(1) NOT NULL DEFAULT 1,
  `acepta_terminos` tinyint(1) NOT NULL DEFAULT 0,
  `es_lote_anonimo` tinyint(1) NOT NULL DEFAULT 0,
  `registrado_por` bigint(20) unsigned DEFAULT NULL,
  `verificado_por` bigint(20) unsigned DEFAULT NULL,
  `verificado_at` timestamp NULL DEFAULT NULL,
  `ip_origen` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `donaciones_mp_payment_id_unique` (`mp_payment_id`),
  KEY `donaciones_registrado_por_foreign` (`registrado_por`),
  KEY `donaciones_verificado_por_foreign` (`verificado_por`),
  KEY `donaciones_estado_index` (`estado`),
  KEY `donaciones_canal_pago_index` (`canal_pago`),
  KEY `donaciones_proveedor_pago_index` (`proveedor_pago`),
  KEY `donaciones_mp_preference_id_index` (`mp_preference_id`),
  KEY `donaciones_fondo_id_foreign` (`fondo_id`),
  CONSTRAINT `donaciones_fondo_id_foreign` FOREIGN KEY (`fondo_id`) REFERENCES `fondos` (`id`),
  CONSTRAINT `donaciones_registrado_por_foreign` FOREIGN KEY (`registrado_por`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `donaciones_verificado_por_foreign` FOREIGN KEY (`verificado_por`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fondo_medios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fondo_medios` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fondo_id` bigint(20) unsigned NOT NULL,
  `tipo` varchar(10) NOT NULL,
  `ruta` varchar(255) NOT NULL,
  `alt` varchar(200) DEFAULT NULL,
  `orden` smallint(6) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fondo_medios_fondo_id_orden_index` (`fondo_id`,`orden`),
  CONSTRAINT `fondo_medios_fondo_id_foreign` FOREIGN KEY (`fondo_id`) REFERENCES `fondos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fondos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fondos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(160) NOT NULL,
  `nombre` varchar(200) NOT NULL,
  `resumen` varchar(300) NOT NULL,
  `descripcion` longtext DEFAULT NULL,
  `imagen_portada` varchar(255) DEFAULT NULL,
  `video` varchar(255) DEFAULT NULL,
  `meta` decimal(12,2) DEFAULT NULL,
  `moneda` char(3) NOT NULL DEFAULT 'PEN',
  `recaudado` decimal(12,2) NOT NULL DEFAULT 0.00,
  `donaciones_count` int(10) unsigned NOT NULL DEFAULT 0,
  `fecha_inicio` date DEFAULT NULL,
  `fecha_fin` date DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'borrador',
  `color_token` varchar(20) NOT NULL DEFAULT 'teal',
  `orden` smallint(6) NOT NULL DEFAULT 0,
  `es_predeterminado` tinyint(1) NOT NULL DEFAULT 0,
  `creado_por` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fondos_slug_unique` (`slug`),
  KEY `fondos_creado_por_foreign` (`creado_por`),
  KEY `fondos_estado_index` (`estado`),
  KEY `fondos_orden_index` (`orden`),
  CONSTRAINT `fondos_creado_por_foreign` FOREIGN KEY (`creado_por`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `webhook_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `webhook_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `evento` varchar(100) DEFAULT NULL,
  `recurso_id` varchar(80) DEFAULT NULL,
  `ts_notificacion` varchar(40) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `firma_valida` tinyint(1) NOT NULL DEFAULT 0,
  `x_request_id` varchar(120) DEFAULT NULL,
  `procesado` tinyint(1) NOT NULL DEFAULT 0,
  `intentos` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `error_mensaje` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `webhook_logs_status_index` (`status`),
  KEY `webhook_logs_procesado_index` (`procesado`),
  KEY `webhook_logs_dedup_index` (`evento`,`recurso_id`,`ts_notificacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50001 DROP VIEW IF EXISTS `v_fondos_conciliacion`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY INVOKER */
/*!50001 VIEW `v_fondos_conciliacion` AS select `f`.`id` AS `fondo_id`,`f`.`slug` AS `slug`,`f`.`recaudado` AS `recaudado`,coalesce(sum(case when `d`.`estado` = 'aprobado' then coalesce(`d`.`monto_real`,`d`.`monto_referencial`) else 0 end),0) AS `total_real`,`f`.`recaudado` - coalesce(sum(case when `d`.`estado` = 'aprobado' then coalesce(`d`.`monto_real`,`d`.`monto_referencial`) else 0 end),0) AS `diferencia`,count(case when `d`.`estado` = 'aprobado' then 1 end) AS `donaciones_aprobadas` from (`fondos` `f` left join `donaciones` `d` on(`d`.`fondo_id` = `f`.`id`)) group by `f`.`id`,`f`.`slug`,`f`.`recaudado` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- =============================================================================
-- MIGRACIONES YA APLICADAS
--
-- Sin estas filas, `php artisan migrate` intentaria crear otra vez unas tablas
-- que ya existen y se caeria en la primera.
-- =============================================================================

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
  (1, '0001_01_01_000000_create_users_table', 1),
  (2, '0001_01_01_000001_create_cache_table', 1),
  (3, '0001_01_01_000002_create_jobs_table', 1),
  (4, '2026_09_18_000100_create_admin_users_table', 2),
  (5, '2026_09_18_000200_create_donaciones_table', 2),
  (6, '2026_09_18_000300_create_webhook_logs_table', 2),
  (7, '2026_09_27_000100_add_campos_mp_a_donaciones_table', 3),
  (8, '2026_09_27_000200_add_deduplicacion_a_webhook_logs_table', 3),
  (9, '2026_09_27_000300_create_fondos_table', 4),
  (10, '2026_09_27_000400_create_fondo_medios_table', 4),
  (11, '2026_09_27_000500_add_fondo_id_a_donaciones_table', 4),
  (12, '2026_09_27_000600_backfill_fondo_id_en_donaciones', 5),
  (13, '2026_09_27_000700_hacer_fondo_id_obligatorio_y_quitar_categoria', 5),
  (14, '2026_09_27_000800_create_v_fondos_conciliacion_view', 5);

-- =============================================================================
-- FONDO INICIAL
--
-- ATENCION: `resumen` lleva un texto marcador y `descripcion`, `meta`,
-- `fecha_inicio` y `fecha_fin` van a NULL. Son los cuatro campos que la
-- fundacion tiene que rellenar desde el panel ANTES de anunciar la campana:
-- el resumen se publica tal cual en la portada y en /api/dashboard.
--
-- Con `meta` a NULL la barra de progreso se oculta, que es lo correcto
-- mientras no haya una cifra acordada.
-- =============================================================================

INSERT INTO `fondos`
  (`id`, `slug`, `nombre`, `resumen`, `descripcion`, `imagen_portada`, `video`,
   `meta`, `moneda`, `recaudado`, `donaciones_count`, `fecha_inicio`, `fecha_fin`,
   `estado`, `color_token`, `orden`, `es_predeterminado`, `creado_por`,
   `created_at`, `updated_at`)
VALUES
  (1, 'fundacion-antonia', 'Fundacion Antonia',
   'PENDIENTE: redactar resumen del fondo', NULL,
   'media/comunitarios-fundacion-territorial-puno.jpg', 'media/fondo_antonia.mp4',
   NULL, 'PEN', 0.00, 0, NULL, NULL,
   'activo', 'teal', 1, 1, NULL,
   NOW(), NOW());
