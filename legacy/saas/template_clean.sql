-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 07-02-2026 a las 23:47:38
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `core_db`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `accessories_checklist`
--

CREATE TABLE `accessories_checklist` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `table_name` varchar(255) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `billing_config`
--

CREATE TABLE `billing_config` (
  `id` int(10) UNSIGNED NOT NULL,
  `config_key` varchar(150) NOT NULL,
  `config_value` text NOT NULL,
  `config_type` enum('json','string','number') NOT NULL DEFAULT 'json',
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `billing_config`
--

INSERT INTO `billing_config` (`id`, `config_key`, `config_value`, `config_type`, `description`, `created_at`, `updated_at`) VALUES
(1, 'payment_methods', '[\"Efectivo\",\"Transferencia\",\"Tarjeta\"]', 'json', 'M??todos de pago disponibles para el m??dulo de facturaci??n', '2025-10-19 17:42:22', '2025-10-19 17:42:22');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `brands`
--

CREATE TABLE `brands` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `brands`
--

INSERT INTO `brands` (`id`, `name`, `description`, `logo_path`, `logo`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'ASUS', '', NULL, 'uploads/brands/ASUS.png', 1, '2025-09-03 06:45:51', '2026-01-23 19:05:01'),
(2, 'HP', '', NULL, 'uploads/brands/HP.png', 1, '2025-09-03 06:47:53', '2026-01-23 19:05:01'),
(3, 'Apple', 'Marca Apple', NULL, 'uploads/brands/Apple.png', 1, '2025-09-04 14:44:02', '2026-01-23 19:05:01'),
(4, 'Samsung', 'Marca Samsung', NULL, NULL, 1, '2025-09-04 14:44:02', '2025-09-04 14:44:02'),
(5, 'Huawei', 'Marca Huawei', NULL, NULL, 1, '2025-09-04 14:44:02', '2025-09-04 14:44:02'),
(6, 'Xiaomi', 'Marca Xiaomi', NULL, NULL, 1, '2025-09-04 14:44:02', '2025-09-04 14:44:02'),
(7, 'LG', 'Marca LG', NULL, NULL, 1, '2025-09-04 14:44:02', '2025-09-04 14:44:02'),
(10, 'Dell', 'Marca Dell', NULL, 'uploads/brands/Dell.jpg', 1, '2025-09-04 14:44:02', '2026-01-23 19:05:01'),
(11, 'Lenovo', 'Marca Lenovo', NULL, 'uploads/brands/Lenovo.png', 1, '2025-09-04 14:44:02', '2026-01-23 19:05:01'),
(13, 'Acer', 'Marca Acer.', NULL, 'uploads/brands/Acer.png', 1, '2025-09-04 14:44:02', '2026-02-06 03:15:00'),
(14, 'MSI', 'Marca MSI', NULL, NULL, 1, '2025-09-04 14:44:02', '2025-09-04 14:44:02'),
(15, 'Toshiba', 'Marca Toshiba', NULL, NULL, 1, '2025-09-04 14:44:02', '2025-09-04 14:44:02'),
(17, 'Epson', 'Marca Epson', NULL, NULL, 1, '2025-09-04 14:44:02', '2025-09-04 14:44:02'),
(19, 'Nintendo', 'Marca Nintendo', NULL, NULL, 1, '2025-09-04 14:44:02', '2025-09-04 14:44:02'),
(20, 'Microsoft', 'Marca Microsoft', NULL, NULL, 1, '2025-09-04 14:44:02', '2025-09-04 14:44:02'),
(23, 'Quanta Computer', '', NULL, 'uploads/brands/Quanta_Computer.png', 1, '2025-10-20 19:04:25', '2026-01-23 19:05:01');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cash_closing_denominations`
--

CREATE TABLE `cash_closing_denominations` (
  `id` int(11) NOT NULL,
  `cash_session_id` int(10) UNSIGNED NOT NULL,
  `denomination_value` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cash_expenses`
--

CREATE TABLE `cash_expenses` (
  `id` int(10) UNSIGNED NOT NULL,
  `cash_session_id` int(10) UNSIGNED NOT NULL,
  `concept` varchar(255) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `receipt_image` varchar(255) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cash_income`
--

CREATE TABLE `cash_income` (
  `id` int(10) UNSIGNED NOT NULL,
  `cash_session_id` int(10) UNSIGNED NOT NULL,
  `income_type` enum('manual','product','service','other') DEFAULT 'manual',
  `concept_id` int(10) UNSIGNED DEFAULT NULL,
  `concept` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `payment_method` varchar(100) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_account_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `cash_income`
--



-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cash_sessions`
--

CREATE TABLE `cash_sessions` (
  `id` int(10) UNSIGNED NOT NULL,
  `session_number` varchar(50) NOT NULL,
  `opened_by` int(10) UNSIGNED NOT NULL,
  `opening_date` datetime NOT NULL DEFAULT current_timestamp(),
  `initial_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `observations` text DEFAULT NULL,
  `closed_by` int(10) UNSIGNED DEFAULT NULL,
  `closing_date` datetime DEFAULT NULL,
  `final_amount` decimal(12,2) DEFAULT 0.00,
  `total_cash` decimal(12,2) DEFAULT 0.00,
  `total_transfer` decimal(12,2) DEFAULT 0.00,
  `total_card` decimal(12,2) DEFAULT 0.00,
  `total_other` decimal(12,2) DEFAULT 0.00,
  `system_total` decimal(12,2) DEFAULT 0.00,
  `physical_count` decimal(12,2) DEFAULT 0.00,
  `difference` decimal(12,2) DEFAULT 0.00,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `cash_sessions`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clients`
--

CREATE TABLE `clients` (
  `id` int(11) NOT NULL,
  `client_type` enum('individual','company') DEFAULT 'individual',
  `first_name` varchar(100) DEFAULT NULL,
  `company_name` varchar(200) DEFAULT NULL,
  `tax_id` varchar(50) DEFAULT NULL,
  `legal_representative` varchar(200) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `id_number` varchar(20) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `clients`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `company_config`
--

CREATE TABLE `company_config` (
  `id` int(11) NOT NULL,
  `company_name` varchar(200) NOT NULL DEFAULT 'Nexar',
  `company_logo` varchar(255) DEFAULT 'company_logo.png',
  `company_phone` varchar(50) DEFAULT NULL,
  `company_email` varchar(255) DEFAULT NULL,
  `company_website` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `company_address` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `company_config`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `company_settings`
--

CREATE TABLE `company_settings` (
  `id` int(11) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `company_address` text DEFAULT NULL,
  `company_phone` varchar(50) DEFAULT NULL,
  `company_email` varchar(255) DEFAULT NULL,
  `company_website` varchar(255) DEFAULT NULL,
  `tax_id` varchar(50) DEFAULT NULL,
  `logo_path` varchar(500) DEFAULT NULL,
  `background_image` varchar(500) DEFAULT NULL,
  `signature_path` varchar(500) DEFAULT NULL,
  `default_currency` varchar(10) NOT NULL DEFAULT 'USD',
  `date_format` varchar(20) NOT NULL DEFAULT 'd/m/Y',
  `number_format` varchar(20) NOT NULL DEFAULT 'en_US',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `company_settings`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `device_types`
--

CREATE TABLE `device_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_visible` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `device_types`
--

INSERT INTO `device_types` (`id`, `name`, `description`, `is_visible`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'portatil', NULL, 1, 1, 2, '2025-09-01 02:31:19', '2026-01-22 18:36:00'),
(2, 'Motherboard', '', 1, 1, 1, '2025-09-02 12:40:13', '2026-01-22 18:36:00'),
(4, 'PC de Escritorio', 'Computadoras de escritorio', 1, 1, 7, '2025-09-03 03:28:36', '2026-01-22 18:36:00'),
(5, 'Celular', 'Teléfonos móviles', 0, 1, 8, '2025-09-03 03:28:36', '2026-01-22 18:36:00'),
(8, 'Todo en Uno', 'Computadoras todo en uno', 1, 1, 9, '2025-09-03 03:28:36', '2026-01-22 18:36:00'),
(9, 'Consola', 'Consolas de videojuegos', 0, 1, 10, '2025-09-03 03:28:36', '2026-01-22 18:36:00'),
(10, 'TV', 'Televisores', 0, 1, 11, '2025-09-03 03:28:36', '2026-01-22 18:36:00'),
(11, 'Monitor', 'Monitores y pantallas', 1, 1, 12, '2025-09-03 03:28:36', '2026-01-22 18:36:00'),
(12, 'Impresora', 'Impresoras y escáneres', 0, 1, 13, '2025-09-03 03:28:36', '2026-01-22 18:36:00'),
(14, 'Otros', 'Otros dispositivos electrónicos', 0, 1, 16, '2025-09-03 03:28:36', '2026-01-22 18:36:00'),
(25, 'Teclado', 'Teclados y periféricos de entrada', 0, 1, 14, '2025-09-03 03:55:02', '2026-01-22 18:36:00'),
(26, 'Mouse', 'Ratones y dispositivos apuntadores', 0, 1, 15, '2025-09-03 03:55:02', '2026-01-22 18:36:00'),
(28, 'Cámara', 'Cámaras web y de seguridad', 0, 1, 17, '2025-09-03 03:55:02', '2026-01-22 18:36:00'),
(29, 'Router', 'Routers y dispositivos de red', 0, 1, 18, '2025-09-03 03:55:02', '2026-01-22 18:36:00'),
(31, 'Cargador', 'Cargadores y adaptadores', 1, 1, 19, '2025-09-03 03:55:02', '2026-01-22 18:36:00'),
(36, 'Tablet', 'Tipo de dispositivo: Tablet', 0, 1, 6, '2025-09-04 14:44:02', '2026-01-22 18:36:00'),
(37, 'Smartphone', 'Tipo de dispositivo: Smartphone', 0, 1, 5, '2025-09-04 14:44:02', '2026-01-22 18:36:00'),
(40, 'Servidor', 'Tipo de dispositivo: Servidor', 1, 1, 3, '2025-09-04 14:44:02', '2026-01-22 18:36:00'),
(41, 'Consola de Videojuegos', 'Tipo de dispositivo: Consola de Videojuegos', 0, 1, 4, '2025-09-04 14:44:02', '2026-01-28 20:37:16');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `reference_type` enum('work_order','sale','purchase_order','client','supplier','manual') NOT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `document_number` varchar(50) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `data` longtext DEFAULT NULL CHECK (json_valid(`data`)),
  `file_path` varchar(500) DEFAULT NULL,
  `status` enum('draft','generated','sent','archived') NOT NULL DEFAULT 'draft',
  `generated_by` int(11) NOT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `document_fields`
--

CREATE TABLE `document_fields` (
  `id` int(11) NOT NULL,
  `field_key` varchar(100) NOT NULL,
  `field_name` varchar(255) NOT NULL,
  `field_type` enum('text','number','date','currency','boolean','image') NOT NULL,
  `source_table` varchar(100) DEFAULT NULL,
  `source_column` varchar(100) DEFAULT NULL,
  `format_pattern` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `category` enum('client','order','product','company','custom') NOT NULL DEFAULT 'custom',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `document_fields`
--

INSERT INTO `document_fields` (`id`, `field_key`, `field_name`, `field_type`, `source_table`, `source_column`, `format_pattern`, `description`, `category`, `is_active`, `created_at`) VALUES
(1, 'client.name', 'Nombre del Cliente', 'text', 'clients', 'first_name', NULL, 'Nombre completo del cliente', 'client', 1, '2025-10-20 20:41:35'),
(2, 'client.email', 'Email del Cliente', 'text', 'clients', 'email', NULL, 'Dirección de email del cliente', 'client', 1, '2025-10-20 20:41:35'),
(3, 'client.phone', 'Teléfono del Cliente', 'text', 'clients', 'phone', NULL, 'Número de teléfono del cliente', 'client', 1, '2025-10-20 20:41:35'),
(4, 'client.address', 'Dirección del Cliente', 'text', 'clients', 'address', NULL, 'Dirección completa del cliente', 'client', 1, '2025-10-20 20:41:35'),
(5, 'client.tax_id', 'RUC/CI del Cliente', 'text', 'clients', 'tax_id', NULL, 'Número de identificación fiscal', 'client', 1, '2025-10-20 20:41:35'),
(6, 'order.id', 'Número de Orden', 'number', 'work_orders', 'id', NULL, 'Número único del orden', 'order', 1, '2025-10-20 20:41:35'),
(7, 'order.created_at', 'Fecha de Creación', 'date', 'work_orders', 'created_at', NULL, 'Fecha de creación de la orden', 'order', 1, '2025-10-20 20:41:35'),
(8, 'order.device_brand', 'Marca del Dispositivo', 'text', 'work_orders', 'device_brand', NULL, 'Marca del dispositivo', 'order', 1, '2025-10-20 20:41:35'),
(9, 'order.device_model', 'Modelo del Dispositivo', 'text', 'work_orders', 'device_model', NULL, 'Modelo del dispositivo', 'order', 1, '2025-10-20 20:41:35'),
(10, 'order.serial_number', 'Número de Serie', 'text', 'work_orders', 'serial_number', NULL, 'Número de serie del dispositivo', 'order', 1, '2025-10-20 20:41:35'),
(11, 'order.reported_issue', 'Problema Reportado', 'text', 'work_orders', 'reported_issue', NULL, 'Descripción del problema', 'order', 1, '2025-10-20 20:41:35'),
(12, 'order.diagnosis', 'Diagnóstico', 'text', 'work_orders', 'diagnosis', NULL, 'Diagnóstico técnico', 'order', 1, '2025-10-20 20:41:35'),
(13, 'order.solution', 'Solución', 'text', 'work_orders', 'solution', NULL, 'Solución aplicada', 'order', 1, '2025-10-20 20:41:35'),
(14, 'order.estimated_cost', 'Costo Estimado', 'currency', 'work_orders', 'estimated_cost', NULL, 'Costo estimado del servicio', 'order', 1, '2025-10-20 20:41:35'),
(15, 'order.final_cost', 'Costo Final', 'currency', 'work_orders', 'final_cost', NULL, 'Costo final del servicio', 'order', 1, '2025-10-20 20:41:35'),
(16, 'order.status', 'Estado', 'text', 'work_orders', 'status', NULL, 'Estado actual de la orden', 'order', 1, '2025-10-20 20:41:35'),
(17, 'company.name', 'Nombre de la Empresa', 'text', 'company_settings', 'company_name', NULL, 'Nombre de la empresa', 'company', 1, '2025-10-20 20:41:35'),
(18, 'company.address', 'Dirección de la Empresa', 'text', 'company_settings', 'company_address', NULL, 'Dirección de la empresa', 'company', 1, '2025-10-20 20:41:35'),
(19, 'company.phone', 'Teléfono de la Empresa', 'text', 'company_settings', 'company_phone', NULL, 'Teléfono de la empresa', 'company', 1, '2025-10-20 20:41:35'),
(20, 'company.email', 'Email de la Empresa', 'text', 'company_settings', 'company_email', NULL, 'Email de la empresa', 'company', 1, '2025-10-20 20:41:35'),
(21, 'company.website', 'Sitio Web', 'text', 'company_settings', 'company_website', NULL, 'Sitio web de la empresa', 'company', 1, '2025-10-20 20:41:35'),
(22, 'company.tax_id', 'RUC de la Empresa', 'text', 'company_settings', 'tax_id', NULL, 'RUC de la empresa', 'company', 1, '2025-10-20 20:41:35'),
(23, 'company.logo', 'Logo de la Empresa', 'image', 'company_settings', 'logo_path', NULL, 'Logo de la empresa', 'company', 1, '2025-10-20 20:41:35'),
(24, 'current.date', 'Fecha Actual', 'date', NULL, NULL, NULL, 'Fecha actual del sistema', 'custom', 1, '2025-10-20 20:41:35'),
(25, 'current.time', 'Hora Actual', 'text', NULL, NULL, NULL, 'Hora actual del sistema', 'custom', 1, '2025-10-20 20:41:35'),
(26, 'document.number', 'Número de Documento', 'text', 'documents', 'document_number', NULL, 'Número único del documento', 'custom', 1, '2025-10-20 20:41:35'),
(27, 'document.title', 'Título del Documento', 'text', 'documents', 'title', NULL, 'Título del documento', 'custom', 1, '2025-10-20 20:41:35');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `document_templates`
--

CREATE TABLE `document_templates` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'Ej: Ticket Venta 80mm',
  `module_type` enum('work_order','sale','quote','report') NOT NULL,
  `paper_size` enum('letter','half_letter','legal','ticket_80mm','ticket_58mm','sticker_50x25') NOT NULL DEFAULT 'letter',
  `content_html` mediumtext NOT NULL COMMENT 'Estructura HTML con variables {{var}}',
  `content_css` text DEFAULT NULL COMMENT 'Estilos CSS especÝficos',
  `is_active` tinyint(1) DEFAULT 1,
  `is_default` tinyint(1) DEFAULT 0 COMMENT 'Si es la plantilla por defecto para este m¾dulo',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `document_templates`
--

INSERT INTO `document_templates` (`id`, `name`, `module_type`, `paper_size`, `content_html`, `content_css`, `is_active`, `is_default`, `created_at`, `updated_at`, `created_by`) VALUES
(9, 'Plantilla1', 'work_order', 'letter', '\r\n<!-- Rendered Document -->\r\n<div id=\"document-root\" style=\"position: relative; width: 100%; height: 100%;\">\r\n<div class=\"doc-block\" id=\"blk_17699887862994jyo2\" style=\"position: absolute; left: 40px; top: 30px; width: 60px; height: 60px; box-sizing: border-box;\"><div style=\"width: 100%; height: 100%; display: flex; justify-content: flex-start;\">\r\n                <img src=\"https://via.placeholder.com/60x60.png?text=Logo\" style=\"width: 100%; height: 100%; object-fit: contain; display: block;\">\r\n            </div></div>\r\n<div class=\"doc-block\" id=\"blk_1769988786299b5o2l\" style=\"position: absolute; left: 85px; top: 25px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 16px; color: #d1d5db; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">Nexar</div></div>\r\n<div class=\"doc-block\" id=\"blk_17699887862999hpmh\" style=\"position: absolute; left: 290px; top: 45px; width: 150px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 11px; color: #d1d5db; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">+573107031821\r\ncll.11av.0cc gran bulevar loc.213\r\nnexar</div></div>\r\n<div class=\"doc-block\" id=\"blk_1769988786299fnl6i\" style=\"position: absolute; left: 190px; top: 35px; width: 100px; height: auto; box-sizing: border-box;\">\r\n            <div style=\"text-align: center;\">\r\n                <img src=\"https://api.qrserver.com/v1/create-qr-code/?size=60x60&data=ORD-000006\" \r\n                     style=\"width: 60px; height: 60px; display: inline-block;\">\r\n            </div>\r\n        </div>\r\n<div class=\"doc-block\" id=\"blk_1769988786299u3093\" style=\"position: absolute; left: 585px; top: 20px; width: 140px; height: 25px; box-sizing: border-box;\"><div style=\"width: 100%; height: 100%; background-color: #d1d5db; border: 1px solid transparent;\"></div></div>\r\n<div class=\"doc-block\" id=\"blk_17699887862992n607\" style=\"position: absolute; left: 630px; top: 20px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">ORDEN DE SERVICIO</div></div>\r\n<div class=\"doc-block\" id=\"blk_1769988786299sqn5l\" style=\"position: absolute; left: 585px; top: 45px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">N??mero:</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629986xtr\" style=\"position: absolute; left: 655px; top: 45px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #ef4444; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">{{order.id}}</div></div>\r\n<div class=\"doc-block\" id=\"blk_17699887862991xc00\" style=\"position: absolute; left: 585px; top: 65px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">Fecha:</div></div>\r\n<div class=\"doc-block\" id=\"blk_17699887862996n0ye\" style=\"position: absolute; left: 655px; top: 65px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">{{order.created_at}}</div></div>\r\n<div class=\"doc-block\" id=\"blk_1769988786299l0aqy\" style=\"position: absolute; left: 40px; top: 100px; width: 340px; height: 25px; box-sizing: border-box;\"><div style=\"width: 100%; height: 100%; background-color: #d1d5db; border: 1px solid transparent;\"></div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629910sqe\" style=\"position: absolute; left: 155px; top: 100px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">DATOS DEL CLIENTE</div></div>\r\n<div class=\"doc-block\" id=\"blk_17699887862993gywo\" style=\"position: absolute; left: 390px; top: 100px; width: 335px; height: 25px; box-sizing: border-box;\"><div style=\"width: 100%; height: 100%; background-color: #d1d5db; border: 1px solid transparent;\"></div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900aj0\" style=\"position: absolute; left: 490px; top: 100px; width: 150px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">DATOS DEL EQUIPO</div></div>\r\n<div class=\"doc-block\" id=\"blk_17699887862997645o\" style=\"position: absolute; left: 40px; top: 130px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">Nombre:</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629929007\" style=\"position: absolute; left: 100px; top: 130px; width: 280px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">{{client.name}}</div></div>\r\n<div class=\"doc-block\" id=\"blk_1769988786299l6006\" style=\"position: absolute; left: 40px; top: 150px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">Direcci??n:</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900008\" style=\"position: absolute; left: 100px; top: 150px; width: 280px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">{{client.address}}</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900009\" style=\"position: absolute; left: 40px; top: 170px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">Tel??fono:</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900010\" style=\"position: absolute; left: 100px; top: 170px; width: 280px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">{{client.phone}}</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900011\" style=\"position: absolute; left: 390px; top: 130px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">Equipo:</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900012\" style=\"position: absolute; left: 450px; top: 130px; width: 275px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">{{order.device_brand}} {{order.device_model}}</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900013\" style=\"position: absolute; left: 390px; top: 150px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">Serial:</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900014\" style=\"position: absolute; left: 450px; top: 150px; width: 275px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">{{order.serial_number}}</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900015\" style=\"position: absolute; left: 40px; top: 200px; width: 685px; height: 25px; box-sizing: border-box;\"><div style=\"width: 100%; height: 100%; background-color: #d1d5db; border: 1px solid transparent;\"></div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900016\" style=\"position: absolute; left: 330px; top: 200px; width: 150px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">FALLA REPORTADA</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900017\" style=\"position: absolute; left: 40px; top: 230px; width: 685px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">{{order.reported_issue}}</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900018\" style=\"position: absolute; left: 40px; top: 280px; width: 685px; height: 25px; box-sizing: border-box;\"><div style=\"width: 100%; height: 100%; background-color: #d1d5db; border: 1px solid transparent;\"></div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900019\" style=\"position: absolute; left: 330px; top: 280px; width: 150px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">TERMINOS Y CONDICIONES</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900020\" style=\"position: absolute; left: 40px; top: 310px; width: 685px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 10px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">1. La empresa no se hace responsable por p??rdida de informaci??n.\r\n2. Pasados 30 d??as sin retirar el equipo, se cobrar?? bodegaje.\r\n3. La garant??a cubre solo el servicio realizado.</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900021\" style=\"position: absolute; left: 40px; top: 400px; width: 250px; height: 1px; box-sizing: border-box;\"><div style=\"width: 100%; height: 100%; border-top: 1px solid #000000;\"></div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900022\" style=\"position: absolute; left: 475px; top: 400px; width: 250px; height: 1px; box-sizing: border-box;\"><div style=\"width: 100%; height: 100%; border-top: 1px solid #000000;\"></div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900023\" style=\"position: absolute; left: 110px; top: 405px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">Firma Cliente</div></div>\r\n<div class=\"doc-block\" id=\"blk_176998878629900024\" style=\"position: absolute; left: 550px; top: 405px; width: 100px; height: auto; box-sizing: border-box;\"><div style=\"text-align: left; font-size: 14px; color: #000000; background-color: transparent; border: 1px solid transparent; padding: 4px; white-space: pre-wrap;\">Firma T??cnico</div></div>\r\n</div>', NULL, 1, 0, '2025-10-20 20:41:35', '2025-10-20 20:41:35', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `equipment_accessories`
--

CREATE TABLE `equipment_accessories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT 'general',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `equipment_accessories`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `invoices`
--

CREATE TABLE `invoices` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `document_type` varchar(50) DEFAULT NULL,
  `invoice_date` datetime NOT NULL,
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pending_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('pending','partial','paid') NOT NULL DEFAULT 'pending',
  `status` enum('draft','sent','paid','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `terms_conditions` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `cancelled_by` int(10) UNSIGNED DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `invoices`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `invoice_drafts`
--

CREATE TABLE `invoice_drafts` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `draft_data` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `invoice_drafts`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `item_type` enum('manual','product','service') NOT NULL DEFAULT 'manual',
  `description` varchar(255) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `invoice_items`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `invoice_payments`
--

CREATE TABLE `invoice_payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `payment_amount` decimal(12,2) NOT NULL,
  `payment_method` varchar(100) NOT NULL,
  `payment_date` datetime NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `cash_session_id` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `pm_account_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `invoice_payments`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `models`
--

CREATE TABLE `models` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `brand_id` int(11) NOT NULL,
  `device_type_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `models`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `order_checklist`
--

CREATE TABLE `order_checklist` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `checklist_item_id` int(11) NOT NULL,
  `is_checked` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `order_equipment_accessories`
--

CREATE TABLE `order_equipment_accessories` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `accessory_id` int(11) NOT NULL,
  `is_included` tinyint(1) NOT NULL DEFAULT 0,
  `condition_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `order_equipment_accessories`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `order_parts`
--

CREATE TABLE `order_parts` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity_used` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `order_services`
--

CREATE TABLE `order_services` (
  `id` int(11) NOT NULL,
  `work_order_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `total_price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `order_statuses`
--

CREATE TABLE `order_statuses` (
  `id` int(11) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `emoji` varchar(10) DEFAULT '',
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#007bff',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `order_statuses`
--

INSERT INTO `order_statuses` (`id`, `slug`, `emoji`, `name`, `description`, `color`, `is_active`, `is_default`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'pending', '🟡', 'Pendiente', 'Orden creada y pendiente de revisión', '#ffc107', 1, 1, 1, '2025-09-01 02:23:19', '2025-09-03 04:49:36'),
(2, 'received', '📥', 'devolucion', 'Dispositivo recibido en el taller', '#17a2b8', 0, 0, 2, '2025-09-01 02:23:19', '2025-10-20 19:52:27'),
(3, 'diagnosing', '🔍', 'Diagnosticando', 'En proceso de diagnóstico', '#fd7e14', 1, 0, 3, '2025-09-01 02:23:19', '2025-09-03 04:49:36'),
(4, 'waiting_parts', '⏳', 'Esperando Repuestos', 'Esperando repuestos para la reparación', '#6f42c1', 1, 0, 4, '2025-09-01 02:23:19', '2025-09-03 04:49:36'),
(5, 'repairing', '🔧', 'Reparando', 'En proceso de reparación', '#007bff', 1, 0, 5, '2025-09-01 02:23:19', '2025-09-03 04:49:36'),
(6, 'testing', '🔙', 'devolucion', 'devolucion de equipo por componete no conseguido fuera de costo o reparacion no biable', '#e2c1cf', 1, 0, 6, '2025-09-01 02:23:19', '2025-10-20 19:52:13'),
(7, 'completed', '✅', 'Completado', 'Reparación completada exitosamente', '#28a745', 1, 0, 7, '2025-09-01 02:23:19', '2025-09-03 04:49:36'),
(8, 'delivered', '🚚', 'Entregado', 'Dispositivo entregado al cliente', '#6c757d', 1, 0, 8, '2025-09-01 02:23:19', '2025-09-03 04:49:36'),
(9, 'cancelled', '❌', 'Cancelado', 'Orden cancelada', '#dc3545', 1, 0, 9, '2025-09-01 02:23:19', '2025-09-03 04:49:36'),
(19, 'nuevoestad', '📦', 'devolucion', '', '#6c757d', 0, 0, 10, '2025-10-19 22:02:36', '2025-10-20 19:53:12');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `order_status_history`
--

CREATE TABLE `order_status_history` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `status` enum('pending','received','diagnosing','waiting_parts','repairing','testing','completed','delivered','cancelled') NOT NULL,
  `notes` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `order_status_history`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `payment_methods`
--

CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `payment_methods`
--

INSERT INTO `payment_methods` (`id`, `name`, `status`) VALUES
(3, 'nequi', 'active'),
(5, 'bancolombia', 'inactive'),
(6, 'DAVIPLATA', 'active');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `payment_method_accounts`
--

CREATE TABLE `payment_method_accounts` (
  `id` int(11) NOT NULL,
  `method_id` int(11) NOT NULL,
  `account_name` varchar(100) DEFAULT NULL,
  `alias` varchar(100) DEFAULT NULL,
  `account_number` varchar(100) NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `holder_name` varchar(120) DEFAULT NULL,
  `holder_id` varchar(60) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `payment_method_accounts`
--

INSERT INTO `payment_method_accounts` (`id`, `method_id`, `account_name`, `alias`, `account_number`, `type`, `holder_name`, `holder_id`, `is_default`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 3, NULL, '', '321986994', 'ahorros', 'jeisson arevalo', '1090525211', 0, 1, '2025-11-22 15:27:05', '2025-11-22 15:27:05'),
(2, 5, NULL, '', '43534534', 'ahorros', 'werwewer', '23423423', 0, 1, '2025-11-24 20:42:05', '2025-11-24 20:42:05'),
(3, 6, NULL, '', '322323', 'ahorros', 'LEON', '', 1, 1, '2026-01-28 00:10:41', '2026-01-28 00:10:41');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `current_stock` int(11) DEFAULT 0,
  `minimum_stock` int(11) DEFAULT 0,
  `cost_price` decimal(10,2) DEFAULT 0.00,
  `sale_price` decimal(10,2) DEFAULT 0.00,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `products`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `base_price` decimal(10,2) DEFAULT NULL,
  `device_category_id` int(11) DEFAULT NULL,
  `estimated_time` int(11) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `services`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `system_config`
--

CREATE TABLE `system_config` (
  `id` int(11) NOT NULL,
  `config_key` varchar(255) DEFAULT NULL,
  `config_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `system_config`
--

INSERT INTO `system_config` (`id`, `config_key`, `config_value`, `description`, `updated_at`) VALUES
(1, 'company_name', 'jeisson', 'Nombre de la empresa', '2025-09-01 01:00:27'),
(2, 'company_logo', 'fas fa-bolt', 'Logo de la empresa (icono o ruta de imagen)', '2025-09-01 01:00:27'),
(3, 'logo_type', 'icon', 'Tipo de logo: icon o image', '2025-09-01 01:00:27'),
(4, 'system_version', '1.0.0', 'Versión del sistema', '2025-09-01 01:00:27'),
(5, 'maintenance_mode', '0', 'Modo de mantenimiento (0=off, 1=on)', '2025-09-01 01:00:27'),
(6, 'currency', 'COP', 'Código de moneda ISO (ej: COP, USD, EUR)', '2026-01-24 22:34:47'),
(7, 'currency_symbol', '$', 'Símbolo de la moneda (ej: $, €, ¥)', '2025-09-05 06:26:31'),
(8, 'currency_name', 'Peso Colombiano', 'Nombre completo de la moneda', '2026-01-24 22:34:47'),
(9, 'phone_prefix', '+57', 'Indicativo telefónico del país (ej: +57, +1, +34)', '2026-01-24 22:34:47'),
(10, 'phone_country', 'Colombia', 'Nombre del país para el indicativo', '2026-01-24 22:34:47'),
(11, 'time_format', '12', 'Formato de hora: 12 (AM/PM) o 24 (24 horas)', '2025-09-05 06:26:31'),
(12, 'date_format', 'd/m/Y', 'Formato de fecha (ej: d/m/Y, Y-m-d)', '2025-09-05 06:26:31'),
(13, 'datetime_format', 'd/m/Y H:i A', 'Formato de fecha y hora', '2025-09-05 06:26:31'),
(23, 'tax_enabled', '0', NULL, '2026-02-06 14:03:25'),
(24, 'tax_name', 'IVA', NULL, '2026-01-22 15:50:18'),
(25, 'tax_rate', '19', NULL, '2026-02-06 14:02:00'),
(26, 'warranty_days', '90', NULL, '2026-02-02 01:10:20'),
(27, 'abandon_days', '90', NULL, '2026-02-02 01:10:20'),
(28, 'warranty_text', 'garantía de 3 meses según Ley 1480 de 2011. Aplica únicamente sobre la reparación electrónica realizada. No cubre mal uso, humedad, software, virus o intervención de terceros.', NULL, '2026-02-02 01:10:20'),
(29, 'warranty_disclaimers', 'Daños causados por mal uso, negligencia o manipulación indebida del equipo.\r\nDaños ocasionados por humedad, líquidos, corrosión u oxidación.\r\nSobrecargas eléctricas, variaciones de voltaje, descargas eléctricas o uso de cargadores no originales.\r\nFallas relacionadas con software, sistemas operativos, programas, virus, malware o configuraciones.\r\nEquipos intervenidos, abiertos o reparados por terceros no autorizados después del servicio.\r\nDaños en partes o componentes no reparados ni reemplazados por el servicio técnico.', NULL, '2026-02-02 01:10:51'),
(121, 'cloud_backup_enabled', '1', NULL, '2026-02-07 17:36:29'),
(122, 'cloud_provider', 'google_drive', NULL, '2026-01-27 22:31:37'),
(123, 'gdrive_client_id', 'admin@local.test', NULL, '2026-02-01 04:55:08'),
(124, 'gdrive_client_secret', 'admin123', NULL, '2026-02-01 04:55:08'),
(125, 'gdrive_refresh_token', '', NULL, '2026-01-27 22:31:37'),
(193, 'whatsapp_template_reception', '📝 Recepción de equipo\r\n👤 {{cliente}} | ☎️ {{cliente_tel}}\r\n📱 Tipo: {{tipo}}\r\n🏷️ Marca: {{marca}} | Modelo: {{modelo}}\r\n🔢 SN/IMEI: {{sn}}\r\n⚠️ Problema reportado: {{falla}}\r\n💰 Costo aprox: {{valor}}\r\n💳 Abono: {{abono}}\r\n🎒 Accesorios: {{accesorios}}\r\n🧾 Orden #{{orden}}\r\n🏢 {{taller_nombre}} | 📞 {{taller_tel}}', NULL, '2026-02-07 13:36:12'),
(194, 'whatsapp_template_ready', '✅ Equipo listo\r\n👤 {{cliente}}\r\n📱 Tipo: {{tipo}}\r\n🏷️ {{marca}} {{modelo}} (SN {{sn}})\r\n🧾 Orden #{{orden}}\r\n⚠️ Problema: {{falla}}\r\n🔬 Diagnóstico: {{diagnostico}}\r\n🛠️ Solución: {{solucion}}\r\n🎒 Accesorios: {{accesorios}}\r\n💰 Total: {{total}} | 💳 Saldo: {{saldo}}\r\n📍 Retiro: {{fecha_entrega}}\r\n☎️ {{taller_nombre}} | {{taller_tel}}', NULL, '2026-02-05 00:10:10'),
(195, 'whatsapp_template_delivery', '📦 Entrega realizada\r\n👤 {{cliente}}\r\n📱 Tipo: {{tipo}}\r\n🏷️ {{marca}} {{modelo}} (SN {{sn}})\r\n🧾 Orden #{{orden}}\r\n🔬 Diagnóstico: {{diagnostico}}\r\n🛠️ Solución: {{solucion}}\r\n🎒 Accesorios: {{accesorios}}\r\n🙏 Gracias por confiar en nosotros\r\n☎️ {{taller_nombre}} | {{taller_tel}}', NULL, '2026-02-05 00:10:10'),
(196, 'whatsapp_template_sale', '🧾 Comprobante de Venta #{{factura}}\r\n👤 {{cliente}}\r\n🛍️ Detalles: {{detalles}}\r\n💰 Total: {{total}} | 💳 Saldo: {{saldo}}\r\n🙏 ¡Gracias por tu compra!\r\n☎️ {{taller_nombre}} | {{taller_tel}}', NULL, '2026-02-05 00:10:10'),
(431, 'whatsapp_template_test', '🧪 Mensaje de Prueba\r\n👤 {{cliente}}\r\n🧾 Orden #{{orden}}\r\n🛍️ Detalles: {{detalles}}\r\n🔗 Seguimiento: {{url_seguimiento}}\r\n🏢 {{taller_nombre}} | 📞 {{taller_tel}}\r\n✨ Este es un mensaje de prueba con emojis.', NULL, '2026-02-06 18:47:29'),
(444, 'backup_mysqldump_path', '', NULL, '2026-02-07 14:52:50'),
(445, 'backup_mysql_path', '', NULL, '2026-02-07 14:52:50'),
(446, 'backup_include_triggers', '1', NULL, '2026-02-07 14:52:50'),
(447, 'backup_include_routines', '1', NULL, '2026-02-07 14:52:50'),
(448, 'backup_include_events', '1', NULL, '2026-02-07 14:52:50'),
(449, 'backup_retention_zip_count', '10', NULL, '2026-02-07 14:52:50'),
(450, 'backup_retention_sql_count', '5', NULL, '2026-02-07 14:52:50');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `technical_reports`
--

CREATE TABLE `technical_reports` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `report_title` varchar(255) DEFAULT 'Informe Técnico',
  `introduction` text DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `procedure_taken` text DEFAULT NULL,
  `conclusion` text DEFAULT NULL,
  `photos_json` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `technical_reports`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `template_elements`
--

CREATE TABLE `template_elements` (
  `id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `element_type` enum('text','image','table','field','line','rectangle','signature') NOT NULL,
  `element_data` longtext NOT NULL CHECK (json_valid(`element_data`)),
  `position_x` decimal(10,2) NOT NULL DEFAULT 0.00,
  `position_y` decimal(10,2) NOT NULL DEFAULT 0.00,
  `width` decimal(10,2) NOT NULL DEFAULT 100.00,
  `height` decimal(10,2) NOT NULL DEFAULT 20.00,
  `z_index` int(11) NOT NULL DEFAULT 1,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `transaction_categories`
--

CREATE TABLE `transaction_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `type` enum('income','expense') NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `transaction_categories`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','technician','user') DEFAULT 'user',
  `active` tinyint(1) DEFAULT 1,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `users`
--




-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `whatsapp_templates`
--

CREATE TABLE `whatsapp_templates` (
  `id` int(11) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('notification','status_update','reminder','custom') NOT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `message` text NOT NULL,
  `variables` longtext DEFAULT NULL CHECK (json_valid(`variables`)),
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `whatsapp_templates`
--

INSERT INTO `whatsapp_templates` (`id`, `name`, `type`, `subject`, `message`, `variables`, `is_default`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Nueva Orden', 'notification', 'Orden Recibida - {{order_number}}', 'Hola {{client_name}}, hemos recibido tu equipo {{device_model}} para revisión. Tu número de orden es {{order_number}}. Te notificaremos cualquier novedad.', '[\"{{client_name}}\",\"{{order_number}}\",\"{{device_model}}\"]', 1, 'active', '2026-02-03 16:12:15', '2026-02-03 16:12:15'),
(2, 'Orden Finalizada', 'status_update', 'Orden Lista - {{order_number}}', 'Hola {{client_name}}, tu equipo {{device_model}} (Orden: {{order_number}}) está listo para ser retirado. El total a pagar es {{total_amount}}.', '[\"{{client_name}}\",\"{{order_number}}\",\"{{device_model}}\",\"{{total_amount}}\"]', 1, 'active', '2026-02-03 16:12:15', '2026-02-03 16:12:15'),
(3, 'Nueva Venta', 'notification', 'Comprobante de Venta - {{sale_number}}', 'Hola {{client_name}}, gracias por tu compra. Adjuntamos el comprobante de tu venta #{{sale_number}} por un total de {{total_amount}}.', '[\"{{client_name}}\",\"{{sale_number}}\",\"{{total_amount}}\"]', 1, 'active', '2026-02-03 16:12:15', '2026-02-03 16:12:15');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `work_orders`
--

CREATE TABLE `work_orders` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `device_type_id` int(11) DEFAULT NULL,
  `device_category_id` int(11) DEFAULT NULL,
  `device_brand` varchar(100) DEFAULT NULL,
  `device_model` varchar(100) DEFAULT NULL,
  `device_password` varchar(255) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `reported_issue` text NOT NULL,
  `device_photo` text DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `solution` text DEFAULT NULL,
  `status` enum('pending','received','diagnosing','waiting_parts','repairing','testing','completed','delivered','cancelled') DEFAULT 'pending',
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `advance_payment` decimal(10,2) DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_reference` varchar(255) DEFAULT NULL,
  `final_cost` decimal(10,2) DEFAULT NULL,
  `technician_notes` text DEFAULT NULL,
  `received_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `estimated_completion` date DEFAULT NULL,
  `completed_date` timestamp NULL DEFAULT NULL,
  `delivered_date` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `delivery_payment` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `work_orders`
--




--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `accessories_checklist`
--
ALTER TABLE `accessories_checklist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indices de la tabla `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `billing_config`
--
ALTER TABLE `billing_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_billing_config_key` (`config_key`);

--
-- Indices de la tabla `brands`
--
ALTER TABLE `brands`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indices de la tabla `cash_closing_denominations`
--
ALTER TABLE `cash_closing_denominations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cash_session_id` (`cash_session_id`);

--
-- Indices de la tabla `cash_expenses`
--
ALTER TABLE `cash_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_expenses_session` (`cash_session_id`),
  ADD KEY `fk_expense_category` (`category_id`);

--
-- Indices de la tabla `cash_income`
--
ALTER TABLE `cash_income`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_income_session` (`cash_session_id`),
  ADD KEY `idx_income_method` (`payment_method`),
  ADD KEY `fk_income_category` (`category_id`);

--
-- Indices de la tabla `cash_sessions`
--
ALTER TABLE `cash_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_session_number` (`session_number`),
  ADD KEY `idx_sessions_status` (`status`),
  ADD KEY `idx_sessions_opening` (`opening_date`),
  ADD KEY `idx_sessions_opened_by` (`opened_by`);

--
-- Indices de la tabla `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `company_config`
--
ALTER TABLE `company_config`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `company_settings`
--
ALTER TABLE `company_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `device_types`
--
ALTER TABLE `device_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indices de la tabla `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `document_number` (`document_number`),
  ADD KEY `idx_template_id` (`template_id`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `idx_document_number` (`document_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_generated_by` (`generated_by`);

--
-- Indices de la tabla `document_fields`
--
ALTER TABLE `document_fields`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `field_key` (`field_key`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_source` (`source_table`,`source_column`);

--
-- Indices de la tabla `document_templates`
--
ALTER TABLE `document_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `equipment_accessories`
--
ALTER TABLE `equipment_accessories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_equipment_accessories_active` (`is_active`),
  ADD KEY `idx_equipment_accessories_category` (`category`),
  ADD KEY `idx_equipment_accessories_sort` (`sort_order`);

--
-- Indices de la tabla `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_invoice_number` (`invoice_number`),
  ADD KEY `idx_invoices_client` (`client_id`),
  ADD KEY `idx_invoices_date` (`invoice_date`),
  ADD KEY `idx_invoices_status` (`status`),
  ADD KEY `idx_invoices_payment_status` (`payment_status`);

--
-- Indices de la tabla `invoice_drafts`
--
ALTER TABLE `invoice_drafts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_drafts_user` (`user_id`);

--
-- Indices de la tabla `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_items_invoice` (`invoice_id`);

--
-- Indices de la tabla `invoice_payments`
--
ALTER TABLE `invoice_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payments_invoice` (`invoice_id`);

--
-- Indices de la tabla `models`
--
ALTER TABLE `models`
  ADD PRIMARY KEY (`id`),
  ADD KEY `brand_id` (`brand_id`),
  ADD KEY `device_type_id` (`device_type_id`);

--
-- Indices de la tabla `order_checklist`
--
ALTER TABLE `order_checklist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `checklist_item_id` (`checklist_item_id`);

--
-- Indices de la tabla `order_equipment_accessories`
--
ALTER TABLE `order_equipment_accessories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_order_accessory` (`order_id`,`accessory_id`),
  ADD KEY `idx_order_equipment_accessories_order` (`order_id`),
  ADD KEY `idx_order_equipment_accessories_accessory` (`accessory_id`);

--
-- Indices de la tabla `order_parts`
--
ALTER TABLE `order_parts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indices de la tabla `order_services`
--
ALTER TABLE `order_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `work_order_id` (`work_order_id`),
  ADD KEY `service_id` (`service_id`);

--
-- Indices de la tabla `order_statuses`
--
ALTER TABLE `order_statuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indices de la tabla `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indices de la tabla `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `payment_method_accounts`
--
ALTER TABLE `payment_method_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `method_id` (`method_id`);

--
-- Indices de la tabla `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`);

--
-- Indices de la tabla `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `device_category_id` (`device_category_id`);

--
-- Indices de la tabla `system_config`
--
ALTER TABLE `system_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `config_key` (`config_key`);

--
-- Indices de la tabla `technical_reports`
--
ALTER TABLE `technical_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indices de la tabla `template_elements`
--
ALTER TABLE `template_elements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_template_id` (`template_id`),
  ADD KEY `idx_element_type` (`element_type`),
  ADD KEY `idx_position` (`position_x`,`position_y`);

--
-- Indices de la tabla `transaction_categories`
--
ALTER TABLE `transaction_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indices de la tabla `whatsapp_templates`
--
ALTER TABLE `whatsapp_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_name` (`name`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_is_default` (`is_default`),
  ADD KEY `idx_status` (`status`);

--
-- Indices de la tabla `work_orders`
--
ALTER TABLE `work_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `device_category_id` (`device_category_id`),
  ADD KEY `work_orders_ibfk_device_type` (`device_type_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `accessories_checklist`
--
ALTER TABLE `accessories_checklist`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3150;

--
-- AUTO_INCREMENT de la tabla `billing_config`
--
ALTER TABLE `billing_config`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `brands`
--
ALTER TABLE `brands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT de la tabla `cash_closing_denominations`
--
ALTER TABLE `cash_closing_denominations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cash_expenses`
--
ALTER TABLE `cash_expenses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cash_income`
--
ALTER TABLE `cash_income`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT de la tabla `cash_sessions`
--
ALTER TABLE `cash_sessions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT de la tabla `company_settings`
--
ALTER TABLE `company_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `device_types`
--
ALTER TABLE `device_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT de la tabla `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `document_fields`
--
ALTER TABLE `document_fields`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT de la tabla `document_templates`
--
ALTER TABLE `document_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `equipment_accessories`
--
ALTER TABLE `equipment_accessories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=70;

--
-- AUTO_INCREMENT de la tabla `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT de la tabla `invoice_drafts`
--
ALTER TABLE `invoice_drafts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT de la tabla `invoice_payments`
--
ALTER TABLE `invoice_payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT de la tabla `models`
--
ALTER TABLE `models`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT de la tabla `order_checklist`
--
ALTER TABLE `order_checklist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `order_equipment_accessories`
--
ALTER TABLE `order_equipment_accessories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=183;

--
-- AUTO_INCREMENT de la tabla `order_parts`
--
ALTER TABLE `order_parts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `order_services`
--
ALTER TABLE `order_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `order_statuses`
--
ALTER TABLE `order_statuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT de la tabla `order_status_history`
--
ALTER TABLE `order_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT de la tabla `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `payment_method_accounts`
--
ALTER TABLE `payment_method_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `system_config`
--
ALTER TABLE `system_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=455;

--
-- AUTO_INCREMENT de la tabla `technical_reports`
--
ALTER TABLE `technical_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `template_elements`
--
ALTER TABLE `template_elements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=112;

--
-- AUTO_INCREMENT de la tabla `transaction_categories`
--
ALTER TABLE `transaction_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT de la tabla `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `whatsapp_templates`
--
ALTER TABLE `whatsapp_templates`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `work_orders`
--
ALTER TABLE `work_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `cash_closing_denominations`
--
ALTER TABLE `cash_closing_denominations`
  ADD CONSTRAINT `cash_closing_denominations_ibfk_1` FOREIGN KEY (`cash_session_id`) REFERENCES `cash_sessions` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `cash_expenses`
--
ALTER TABLE `cash_expenses`
  ADD CONSTRAINT `fk_expense_category` FOREIGN KEY (`category_id`) REFERENCES `transaction_categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_expenses_session` FOREIGN KEY (`cash_session_id`) REFERENCES `cash_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `cash_income`
--
ALTER TABLE `cash_income`
  ADD CONSTRAINT `fk_income_category` FOREIGN KEY (`category_id`) REFERENCES `transaction_categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_income_session` FOREIGN KEY (`cash_session_id`) REFERENCES `cash_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `document_templates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `fk_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `invoice_payments`
--
ALTER TABLE `invoice_payments`
  ADD CONSTRAINT `fk_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `order_parts`
--
ALTER TABLE `order_parts`
  ADD CONSTRAINT `order_parts_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_parts_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `order_services`
--
ALTER TABLE `order_services`
  ADD CONSTRAINT `order_services_ibfk_1` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_services_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`);

--
-- Filtros para la tabla `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD CONSTRAINT `order_status_history_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Filtros para la tabla `services`
--
ALTER TABLE `services`
  ADD CONSTRAINT `services_ibfk_1` FOREIGN KEY (`device_category_id`) REFERENCES `device_types` (`id`);

--
-- Filtros para la tabla `technical_reports`
--
ALTER TABLE `technical_reports`
  ADD CONSTRAINT `technical_reports_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `template_elements`
--
ALTER TABLE `template_elements`
  ADD CONSTRAINT `template_elements_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `document_templates` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `work_orders`
--
ALTER TABLE `work_orders`
  ADD CONSTRAINT `work_orders_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  ADD CONSTRAINT `work_orders_ibfk_2` FOREIGN KEY (`device_category_id`) REFERENCES `device_types` (`id`),
  ADD CONSTRAINT `work_orders_ibfk_device_type` FOREIGN KEY (`device_type_id`) REFERENCES `device_types` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
