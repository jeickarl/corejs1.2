-- 1. Crear tabla tenants si no existe (usando la estructura de saas_tenants pero mejorada)
CREATE TABLE IF NOT EXISTS `tenants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `status` enum('active','suspended') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- 2. Migrar datos de saas_tenants a tenants si existen
INSERT IGNORE INTO `tenants` (id, company_name, slug, status, created_at)
SELECT id, company_name, db_name, status, created_at FROM `saas_tenants`;

-- 2.1 Asegurar que exista al menos un tenant por defecto si la tabla está vacía
INSERT IGNORE INTO `tenants` (id, company_name, slug, status) VALUES (1, 'Empresa Principal', 'default', 'active');

-- 3. Agregar columna tenant_id a tablas clave (ejemplo con users, repetir para todas)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tenant_id');
SET @sql := IF(@exist = 0, 'ALTER TABLE users ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id, ADD INDEX idx_tenant (tenant_id), ADD CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE', 'SELECT "Column already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Actualizar índice único de users (email -> email + tenant_id)
SET @exist_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'email');
SET @sql_idx := IF(@exist_idx > 0, 'ALTER TABLE users DROP INDEX email', 'SELECT "Index email already dropped"');
PREPARE stmt FROM @sql_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist_new_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'unique_email_tenant');
SET @sql_new_idx := IF(@exist_new_idx = 0, 'ALTER TABLE users ADD UNIQUE KEY unique_email_tenant (email, tenant_id)', 'SELECT "Index unique_email_tenant already exists"');
PREPARE stmt FROM @sql_new_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
