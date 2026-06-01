-- Procedimiento almacenado para agregar tenant_id de forma segura a múltiples tablas
DELIMITER //

CREATE PROCEDURE AddTenantIdToTable(IN tableName VARCHAR(64))
BEGIN
    SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tableName AND COLUMN_NAME = 'tenant_id');
    
    IF @exists = 0 THEN
        SET @sql := CONCAT('ALTER TABLE ', tableName, ' ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id, ADD INDEX idx_tenant (tenant_id), ADD CONSTRAINT fk_', tableName, '_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
        SELECT CONCAT('Table ', tableName, ' updated successfully.') AS result;
    ELSE
        SELECT CONCAT('Table ', tableName, ' already has tenant_id.') AS result;
    END IF;
END //

DELIMITER ;

-- Ejecutar para todas las tablas de negocio identificadas
CALL AddTenantIdToTable('products');
CALL AddTenantIdToTable('clients');
CALL AddTenantIdToTable('services');
CALL AddTenantIdToTable('work_orders');
CALL AddTenantIdToTable('invoices');
CALL AddTenantIdToTable('cash_sessions');
CALL AddTenantIdToTable('system_config');
CALL AddTenantIdToTable('company_settings');

-- Actualizar Índices Únicos (CRÍTICO: Convertir UNIQUE(col) a UNIQUE(col, tenant_id))

-- Products (sku)
SET @exist_sku := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'sku');
SET @sql_sku := IF(@exist_sku > 0, 'ALTER TABLE products DROP INDEX sku', 'SELECT "Index sku already dropped"');
PREPARE stmt FROM @sql_sku; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exist_new_sku := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'unique_sku_tenant');
SET @sql_new_sku := IF(@exist_new_sku = 0, 'ALTER TABLE products ADD UNIQUE KEY unique_sku_tenant (sku, tenant_id)', 'SELECT "Index unique_sku_tenant already exists"');
PREPARE stmt FROM @sql_new_sku; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Invoices (invoice_number)
SET @exist_inv := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND INDEX_NAME = 'uq_invoice_number');
SET @sql_inv := IF(@exist_inv > 0, 'ALTER TABLE invoices DROP INDEX uq_invoice_number', 'SELECT "Index uq_invoice_number already dropped"');
PREPARE stmt FROM @sql_inv; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exist_new_inv := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND INDEX_NAME = 'unique_invoice_tenant');
SET @sql_new_inv := IF(@exist_new_inv = 0, 'ALTER TABLE invoices ADD UNIQUE KEY unique_invoice_tenant (invoice_number, tenant_id)', 'SELECT "Index unique_invoice_tenant already exists"');
PREPARE stmt FROM @sql_new_inv; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Cash Sessions (session_number)
SET @exist_sess := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_sessions' AND INDEX_NAME = 'uq_session_number');
SET @sql_sess := IF(@exist_sess > 0, 'ALTER TABLE cash_sessions DROP INDEX uq_session_number', 'SELECT "Index uq_session_number already dropped"');
PREPARE stmt FROM @sql_sess; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exist_new_sess := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_sessions' AND INDEX_NAME = 'unique_session_tenant');
SET @sql_new_sess := IF(@exist_new_sess = 0, 'ALTER TABLE cash_sessions ADD UNIQUE KEY unique_session_tenant (session_number, tenant_id)', 'SELECT "Index unique_session_tenant already exists"');
PREPARE stmt FROM @sql_new_sess; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- System Config (config_key)
SET @exist_cfg := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_config' AND INDEX_NAME = 'config_key');
SET @sql_cfg := IF(@exist_cfg > 0, 'ALTER TABLE system_config DROP INDEX config_key', 'SELECT "Index config_key already dropped"');
PREPARE stmt FROM @sql_cfg; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exist_new_cfg := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_config' AND INDEX_NAME = 'unique_config_tenant');
SET @sql_new_cfg := IF(@exist_new_cfg = 0, 'ALTER TABLE system_config ADD UNIQUE KEY unique_config_tenant (config_key, tenant_id)', 'SELECT "Index unique_config_tenant already exists"');
PREPARE stmt FROM @sql_new_cfg; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Order Statuses (slug)
SET @exist_os := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_statuses' AND INDEX_NAME = 'uq_order_statuses_slug_tenant');
SET @sql_os := IF(@exist_os = 0, 'ALTER TABLE order_statuses ADD UNIQUE KEY uq_order_statuses_slug_tenant (slug, tenant_id)', 'SELECT "Index uq_order_statuses_slug_tenant already exists"');
PREPARE stmt FROM @sql_os; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS AddTenantIdToTable;
