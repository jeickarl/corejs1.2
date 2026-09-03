// Script: Ejecuta el repairSchema completo en tenant 1 y tenant 2:
// 1) Crea 54 tablas CREATE IF NOT EXISTS
// 2) Asegura 29 reglas de columnas e índices (ADD COLUMN, MODIFY, CREATE INDEX)
// Usa mismos credenciales que master DB.

const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const envPath = path.join(__dirname, 'apps', 'backend', '.env');
let env = {};
try {
  env = fs.readFileSync(envPath, 'utf8').split('\n').reduce((o, line) => {
    const [k, ...rest] = line.split('=');
    if (k && !k.trim().startsWith('#')) o[k.trim()] = rest.join('=').trim();
    return o;
  }, {});
} catch (_) {}

const HOST = env.MASTER_DB_HOST || '127.0.0.1';
const PORT = Number(env.MASTER_DB_PORT || 3306);
const MASTER = env.MASTER_DB_NAME || 'core_master';
const USER = env.MASTER_DB_USER || 'root';
const PASS = env.MASTER_DB_PASS || '';

function decryptPassword(e, env) {
  if (!e.db_password_enc || !e.db_password_iv || !e.db_password_tag) return '';
  try {
    const rawKey = (env.MASTER_DB_KEY || 'CHANGE_ME').trim();
    const decoded = Buffer.from(rawKey, 'base64');
    const key = decoded.length === 32 ? decoded : crypto.createHash('sha256').update(rawKey).digest();
    const decipher = crypto.createDecipheriv('aes-256-gcm', key, Buffer.from(e.db_password_iv, 'base64'));
    decipher.setAuthTag(Buffer.from(e.db_password_tag, 'base64'));
    const dec = Buffer.concat([decipher.update(Buffer.from(e.db_password_enc, 'base64')), decipher.final()]);
    return dec.toString('utf8');
  } catch (_) {
    return '';
  }
}

const DDLS = [
  `CREATE TABLE IF NOT EXISTS accessories_checklist (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, description TEXT NULL, sort_order INT(10) UNSIGNED NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS activity_logs (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT(11) NULL, action VARCHAR(255) NOT NULL, table_name VARCHAR(255) NULL, record_id INT(11) NULL, old_values TEXT NULL, new_values TEXT NULL, ip_address VARCHAR(45) NULL, user_agent VARCHAR(255) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS billing_config (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, config_key VARCHAR(150) NOT NULL, config_value TEXT NOT NULL, config_type ENUM('json','string','number') NOT NULL DEFAULT 'json', description VARCHAR(255) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_billing_config_key (config_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS brands (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, logo VARCHAR(255) NULL, logo_url VARCHAR(255) NULL, description TEXT NULL, sort_order INT(11) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, tenant_id INT(11) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS cache (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, k VARCHAR(191) NOT NULL, v LONGTEXT NULL, expires_at INT(10) UNSIGNED NULL, UNIQUE KEY uq_cache_k (k)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS cash_sessions (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT(10) UNSIGNED NOT NULL, opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00, closing_balance DECIMAL(12,2) NULL, opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, closed_at DATETIME NULL, status VARCHAR(20) NOT NULL DEFAULT 'open', notes TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_user_id (user_id), KEY idx_status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS cash_movements (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, cash_session_id INT(10) UNSIGNED NOT NULL, movement_type ENUM('income','expense','transfer','adjustment') NOT NULL, amount DECIMAL(12,2) NOT NULL, reference_number VARCHAR(100) NULL, related_id INT(10) UNSIGNED NULL, description TEXT NULL, category VARCHAR(100) NULL, created_by INT(10) UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_cash_session_id (cash_session_id), KEY idx_movement_type (movement_type), KEY idx_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS clients (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NULL, company_name VARCHAR(200) NULL, document_type VARCHAR(20) NULL, document_number VARCHAR(50) NULL, email VARCHAR(150) NULL, phone VARCHAR(50) NULL, address TEXT NULL, city VARCHAR(100) NULL, notes TEXT NULL, tenant_id INT(11) NULL, created_by INT(11) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_document (document_type, document_number), KEY idx_email (email), KEY idx_phone (phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS company_config (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, company_name VARCHAR(255) NOT NULL, company_address TEXT NULL, company_phone VARCHAR(50) NULL, company_email VARCHAR(150) NULL, company_website VARCHAR(255) NULL, company_logo VARCHAR(255) NULL, logo_url VARCHAR(255) NULL, document_logo VARCHAR(255) NULL, stamp_image VARCHAR(255) NULL, tax_id VARCHAR(50) NULL, currency VARCHAR(10) NOT NULL DEFAULT 'USD', timezone VARCHAR(50) NOT NULL DEFAULT 'America/Argentina/Buenos_Aires', date_format VARCHAR(20) NOT NULL DEFAULT 'DD/MM/YYYY', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS company_settings (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, tenant_id INT(11) NULL, company_name VARCHAR(255) NOT NULL, company_logo VARCHAR(255) NULL, logo_url VARCHAR(255) NULL, invoice_footer TEXT NULL, terms_and_conditions TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS dashboard_notes (user_id INT NOT NULL, content TEXT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS device_categories (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, description TEXT NULL, sort_order INT(11) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_name (name), KEY idx_active (active), KEY idx_sort (sort_order)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS device_types (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, description TEXT NULL, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT(11) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS document_fields (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, document_template_id INT(11) NOT NULL, field_key VARCHAR(64) NOT NULL, field_label VARCHAR(128) NULL, field_type VARCHAR(32) NOT NULL DEFAULT 'text', required TINYINT(1) NOT NULL DEFAULT 0, sort_order INT(11) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_template (document_template_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS document_templates (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, template_slug VARCHAR(64) NOT NULL, template_name VARCHAR(150) NOT NULL, template_type ENUM('invoice','receipt','quote','work_order','sticker','other') NOT NULL DEFAULT 'invoice', html LONGTEXT NULL, css LONGTEXT NULL, logo_url VARCHAR(255) NULL, is_default TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_slug (template_slug)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS equipment_accessories (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id INT(11) NOT NULL, accessory_id INT(11) NOT NULL, accessory_name VARCHAR(150) NULL, notes TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_order_id (order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS invoice_items (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, invoice_id INT(11) NOT NULL, description TEXT NOT NULL, product_id INT(11) NULL, quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00, unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00, discount DECIMAL(12,2) NOT NULL DEFAULT 0.00, tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00, total DECIMAL(12,2) NOT NULL DEFAULT 0.00, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_invoice_id (invoice_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS invoice_payments (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, invoice_id INT(11) NOT NULL, payment_date DATE NOT NULL, amount DECIMAL(12,2) NOT NULL, payment_method VARCHAR(100) NULL, reference_number VARCHAR(100) NULL, notes TEXT NULL, created_by INT(11) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_invoice_id (invoice_id), KEY idx_payment_date (payment_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS invoices (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, invoice_number VARCHAR(50) NOT NULL, client_id INT(11) NOT NULL, order_id INT(11) NULL, issue_date DATE NOT NULL, due_date DATE NULL, subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00, tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, discount DECIMAL(12,2) NOT NULL DEFAULT 0.00, total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, payment_status ENUM('pending','partially_paid','paid') NOT NULL DEFAULT 'pending', status ENUM('draft','sent','paid','cancelled') NOT NULL DEFAULT 'draft', notes TEXT NULL, terms TEXT NULL, created_by INT(11) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_invoice_number (invoice_number), KEY idx_client_id (client_id), KEY idx_order_id (order_id), KEY idx_issue_date (issue_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS models (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, brand_id INT(11) NOT NULL, device_type_id INT(11) NULL, name VARCHAR(150) NOT NULL, description TEXT NULL, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT(11) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_brand_model (brand_id, name), KEY idx_brand (brand_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS notifications (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, body TEXT NULL, type VARCHAR(32) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS order_items (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id INT(11) NOT NULL, service_id INT(11) NULL, description TEXT NULL, quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00, unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00, discount DECIMAL(12,2) NOT NULL DEFAULT 0.00, tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00, total DECIMAL(12,2) NOT NULL DEFAULT 0.00, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_order_id (order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS order_parts (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id INT(11) NOT NULL, part_name VARCHAR(255) NOT NULL, part_number VARCHAR(100) NULL, quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00, cost DECIMAL(12,2) NOT NULL DEFAULT 0.00, price DECIMAL(12,2) NOT NULL DEFAULT 0.00, supplier VARCHAR(200) NULL, warranty VARCHAR(100) NULL, notes TEXT NULL, created_by INT(11) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, tenant_id INT(11) NULL, KEY idx_order_id (order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS order_status_history (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, status VARCHAR(64) NOT NULL, user_id INT NULL, note TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_order_id (order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS order_statuses (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, emoji VARCHAR(32) NULL, color VARCHAR(16) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0, is_excluded_from_total TINYINT(1) NOT NULL DEFAULT 0, UNIQUE KEY uniq_slug (slug)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS orders (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, order_number VARCHAR(64) NOT NULL, client_id INT(11) NOT NULL, device_type_id INT(11) NULL, brand_id INT(11) NULL, model_id INT(11) NULL, serial_number VARCHAR(150) NULL, imei VARCHAR(50) NULL, password_device VARCHAR(100) NULL, reported_problem TEXT NULL, diagnosis TEXT NULL, work_done TEXT NULL, internal_notes TEXT NULL, status VARCHAR(64) NOT NULL DEFAULT 'pending', priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium', technician_id INT(11) NULL, received_by INT(11) NULL, received_at DATETIME NULL, quoted_amount DECIMAL(12,2) NULL, advance_payment DECIMAL(12,2) NULL, final_amount DECIMAL(12,2) NULL, tenant_id INT(11) NULL, created_by INT(11) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_order_number (order_number), KEY idx_client_id (client_id), KEY idx_status (status), KEY idx_technician_id (technician_id), KEY idx_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS password_reset_tokens (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT(11) NOT NULL, token VARCHAR(191) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_token (token), KEY idx_user_id (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS payment_method_accounts (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, payment_method_id INT(11) NULL, account_name VARCHAR(150) NOT NULL, account_number VARCHAR(100) NULL, bank_name VARCHAR(150) NULL, account_type VARCHAR(50) NULL, holder_name VARCHAR(200) NULL, holder_document VARCHAR(50) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT(11) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS payment_methods (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, code VARCHAR(50) NULL, description TEXT NULL, requires_reference TINYINT(1) NOT NULL DEFAULT 0, sort_order INT(11) NOT NULL DEFAULT 0, is_default TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_code (code), UNIQUE KEY uq_name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS products (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sku VARCHAR(100) NULL, name VARCHAR(255) NOT NULL, description TEXT NULL, category VARCHAR(150) NULL, barcode VARCHAR(100) NULL, cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00, selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00, stock_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00, min_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00, is_active TINYINT(1) NOT NULL DEFAULT 1, created_by INT(10) UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_sku (sku), KEY idx_name (name), KEY idx_barcode (barcode)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS inventory_movements (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id INT(10) UNSIGNED NOT NULL, movement_type ENUM('in','out','adjust','transfer') NOT NULL, quantity DECIMAL(12,2) NOT NULL, unit_cost DECIMAL(12,2) NULL, reference_number VARCHAR(100) NULL, related_id INT(10) UNSIGNED NULL, notes TEXT NULL, created_by INT(10) UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_product (product_id), KEY idx_type (movement_type), KEY idx_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS purchase_orders (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, po_number VARCHAR(50) NOT NULL, supplier_id INT(10) UNSIGNED NOT NULL, order_date DATE NOT NULL, expected_date DATE NULL, notes TEXT NULL, subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00, tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, payment_status ENUM('pending','partially_paid','paid') NOT NULL DEFAULT 'pending', status ENUM('draft','sent','received','cancelled') NOT NULL DEFAULT 'draft', created_by INT(10) UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_po_number (po_number), KEY idx_supplier_id (supplier_id), KEY idx_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS purchase_order_items (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, purchase_order_id INT(10) UNSIGNED NOT NULL, product_id INT(10) UNSIGNED NOT NULL, quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00, unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00, subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00, KEY idx_po_id (purchase_order_id), KEY idx_product_id (product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS purchase_receipts (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, receipt_number VARCHAR(50) NOT NULL, purchase_order_id INT(10) UNSIGNED NOT NULL, supplier_id INT(10) UNSIGNED NOT NULL, received_date DATE NOT NULL, notes TEXT NULL, total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, created_by INT(10) UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_receipt_number (receipt_number), KEY idx_po_id (purchase_order_id), KEY idx_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS purchase_receipt_items (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, receipt_id INT(10) UNSIGNED NOT NULL, product_id INT(10) UNSIGNED NOT NULL, quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00, unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00, subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00, KEY idx_receipt_id (receipt_id), KEY idx_product_id (product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS services (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, description TEXT NULL, device_category_id INT(10) UNSIGNED NOT NULL, base_price DECIMAL(12,2) NOT NULL DEFAULT 0.00, estimated_time INT(11) NOT NULL DEFAULT 0, notes TEXT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_name (name), KEY idx_active (active), KEY idx_category (device_category_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS supplier_payments (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, supplier_id INT(10) UNSIGNED NOT NULL, purchase_order_id INT(10) UNSIGNED NULL, payment_amount DECIMAL(12,2) NOT NULL, payment_method VARCHAR(100) NULL, payment_date DATE NOT NULL, reference_number VARCHAR(100) NULL, notes TEXT NULL, cash_session_id INT(10) UNSIGNED NULL, created_by INT(10) UNSIGNED NULL, request_id VARCHAR(64) NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_supplier_id (supplier_id), KEY idx_purchase_order_id (purchase_order_id), KEY idx_cash_session_id (cash_session_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS suppliers (id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, contact_name VARCHAR(150) NULL, document_number VARCHAR(50) NULL, phone VARCHAR(50) NULL, email VARCHAR(150) NULL, address TEXT NULL, city VARCHAR(100) NULL, notes TEXT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_by INT(10) UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_name (name), KEY idx_document (document_number)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS system_config (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, config_key VARCHAR(100) NOT NULL, config_value LONGTEXT NULL, config_type ENUM('string','number','boolean','json') NOT NULL DEFAULT 'string', description VARCHAR(255) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_config_key (config_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS technical_reports (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, report_title VARCHAR(255) NOT NULL, diagnosis TEXT NULL, procedure_taken TEXT NULL, introduction TEXT NULL, conclusion TEXT NULL, photos_json LONGTEXT NULL, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_order_id (order_id), KEY idx_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS tenant_counters (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, counter_key VARCHAR(64) NOT NULL, counter_value BIGINT(20) NOT NULL DEFAULT 0, prefix VARCHAR(16) NULL, suffix VARCHAR(16) NULL, padding INT(11) NOT NULL DEFAULT 4, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_counter_key (counter_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS tenants (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_slug (slug)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS template_elements (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, document_template_id INT(11) NOT NULL, element_type VARCHAR(32) NOT NULL, element_key VARCHAR(64) NULL, element_label VARCHAR(128) NULL, content TEXT NULL, x_pos INT(11) NULL, y_pos INT(11) NULL, width INT(11) NULL, height INT(11) NULL, sort_order INT(11) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_template (document_template_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS transaction_categories (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, category_type ENUM('income','expense') NOT NULL, description TEXT NULL, is_system TINYINT(1) NOT NULL DEFAULT 0, sort_order INT(11) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_name_type (name, category_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS user_notes (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT(11) NOT NULL, record_type VARCHAR(50) NULL, record_id INT(11) NULL, title VARCHAR(255) NULL, content TEXT NULL, is_pinned TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_user (user_id), KEY idx_record (record_type, record_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS user_notifications (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, notification_id INT NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, read_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_user_read (user_id, is_read), KEY idx_notification_id (notification_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS users (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL, password_hash VARCHAR(255) NOT NULL, first_name VARCHAR(100) NULL, last_name VARCHAR(100) NULL, role ENUM('admin','technician','sales','inventory','cashier','viewer') NOT NULL DEFAULT 'viewer', phone VARCHAR(50) NULL, photo_url VARCHAR(255) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_by INT(11) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_username (username), UNIQUE KEY uq_email (email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS whatsapp_templates (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, template_slug VARCHAR(64) NOT NULL, template_name VARCHAR(150) NOT NULL, language VARCHAR(10) NOT NULL DEFAULT 'es', content LONGTEXT NULL, variables_json LONGTEXT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_slug_lang (template_slug, language)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS work_orders (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, work_order_number VARCHAR(64) NOT NULL, verification_code VARCHAR(16) NULL, approval_status VARCHAR(32) NULL, status VARCHAR(64) NOT NULL DEFAULT 'pending', technician_id INT NULL, scheduled_date DATE NULL, notes TEXT NULL, customer_signature TEXT NULL, approval_signature_path VARCHAR(255) NULL, approval_comment TEXT NULL, approval_signature TEXT NULL, approved_at DATETIME NULL, approved_quote_amount DECIMAL(12,2) NULL, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_work_order_number (work_order_number), KEY idx_order_id (order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  `CREATE TABLE IF NOT EXISTS work_order_services (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, work_order_id INT NOT NULL, service_id INT NOT NULL, quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00, service_price DECIMAL(12,2) NOT NULL DEFAULT 0.00, total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_order_id (work_order_id), KEY idx_service_id (service_id), KEY idx_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
];

const COL_CHECKS = [
  { step: 'brands.logo_url', sqlAdd: 'ALTER TABLE brands ADD COLUMN logo_url VARCHAR(255) NULL AFTER logo' },
  { step: 'company_config.logo_url', sqlAdd: 'ALTER TABLE company_config ADD COLUMN logo_url VARCHAR(255) NULL AFTER company_website' },
  { step: 'work_orders.verification_code modify', sqlAdd: 'ALTER TABLE work_orders MODIFY COLUMN verification_code VARCHAR(16) NULL' },
  { step: 'work_orders.status modify', sqlAdd: 'ALTER TABLE work_orders MODIFY COLUMN status VARCHAR(64) NOT NULL DEFAULT \'pending\'' },
  { step: 'work_orders.approval_status add', sqlAdd: 'ALTER TABLE work_orders ADD COLUMN approval_status VARCHAR(32) NULL AFTER verification_code' },
  { step: 'work_orders.approval_signature_path add', sqlAdd: 'ALTER TABLE work_orders ADD COLUMN approval_signature_path VARCHAR(255) NULL AFTER approval_status' },
  { step: 'work_orders.approved_at add', sqlAdd: 'ALTER TABLE work_orders ADD COLUMN approved_at DATETIME NULL AFTER approval_signature_path' },
  { step: 'work_orders.approved_quote_amount add', sqlAdd: 'ALTER TABLE work_orders ADD COLUMN approved_quote_amount DECIMAL(12,2) NULL AFTER approved_at' },
  { step: 'work_orders.approval_comment add', sqlAdd: 'ALTER TABLE work_orders ADD COLUMN approval_comment TEXT NULL AFTER approved_quote_amount' },
  { step: 'work_orders.approval_signature add', sqlAdd: 'ALTER TABLE work_orders ADD COLUMN approval_signature TEXT NULL AFTER approval_comment' },
  { step: 'invoice_items.product_id', sqlAdd: 'ALTER TABLE invoice_items ADD COLUMN product_id INT(11) NULL AFTER description' },
  { step: 'order_parts.tenant_id', sqlAdd: 'ALTER TABLE order_parts ADD COLUMN tenant_id INT(11) NULL AFTER updated_at' },
  { step: 'order_statuses.is_excluded_from_total', sqlAdd: 'ALTER TABLE order_statuses ADD COLUMN is_excluded_from_total TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order' },
  { step: 'payment_method_accounts.payment_method_id', sqlAdd: 'ALTER TABLE payment_method_accounts ADD COLUMN payment_method_id INT(11) NULL AFTER id' },
  { step: 'payment_method_accounts.account_type', sqlAdd: 'ALTER TABLE payment_method_accounts ADD COLUMN account_type VARCHAR(50) NULL AFTER account_name' },
  { step: 'payment_methods.is_default', sqlAdd: 'ALTER TABLE payment_methods ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order' },
  { step: 'payment_methods.is_active', sqlAdd: 'ALTER TABLE payment_methods ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_default' },
  { step: 'payment_methods.created_at', sqlAdd: 'ALTER TABLE payment_methods ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active' },
  { step: 'payment_methods.updated_at', sqlAdd: 'ALTER TABLE payment_methods ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at' },
  { step: 'user_notifications.is_read', sqlAdd: 'ALTER TABLE user_notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER notification_id' },
  { step: 'user_notifications.created_at', sqlAdd: 'ALTER TABLE user_notifications ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_read' },
  { step: 'work_orders.verification_code add', sqlAdd: 'ALTER TABLE work_orders ADD COLUMN verification_code VARCHAR(16) NULL AFTER order_number' },
  { step: 'work_orders.approval_signature_path add2', sqlAdd: 'ALTER TABLE work_orders ADD COLUMN approval_signature_path VARCHAR(255) NULL AFTER customer_signature' },
  { step: 'work_orders.approval_comment add2', sqlAdd: 'ALTER TABLE work_orders ADD COLUMN approval_comment TEXT NULL AFTER approval_signature_path' },
  { step: 'work_orders.approval_signature add2', sqlAdd: 'ALTER TABLE work_orders ADD COLUMN approval_signature TEXT NULL AFTER approval_comment' },
  { step: 'work_orders.approved_at add2', sqlAdd: 'ALTER TABLE work_orders ADD COLUMN approved_at DATETIME NULL AFTER approval_signature' },
  { step: 'work_orders.approved_quote_amount add2', sqlAdd: 'ALTER TABLE work_orders ADD COLUMN approved_quote_amount DECIMAL(12,2) NULL AFTER approved_at' },
  { step: 'idx_user_read', sqlAdd: 'CREATE INDEX idx_user_read ON user_notifications (user_id, is_read)' },
  { step: 'idx_notification_id', sqlAdd: 'CREATE INDEX idx_notification_id ON user_notifications (notification_id)' },
];

async function repairTenantDb(pool, tenantId, nombre) {
  console.log(`\n--- REPARANDO tenant ${tenantId} (${nombre}) ---`);
  const out = [];
  let ok = 0, fail = 0;
  for (const sql of DDLS) {
    try {
      await pool.query(sql);
      ok++;
    } catch (e) {
      fail++;
    }
  }
  out.push({ fase: 'CREATE TABLE', ok, fail, error: null });
  console.log(`  CREATE TABLE: ok=${ok}, fail=${fail}`);

  ok = 0; fail = 0;
  for (const c of COL_CHECKS) {
    try {
      await pool.query(c.sqlAdd);
      ok++;
    } catch (e) {
      fail++;
    }
  }
  out.push({ fase: 'ALTER/INDEX', ok, fail });
  console.log(`  ALTER/INDEX: ok=${ok}, fail=${fail} (duplicates = idempotency OK)`);
  return out;
}

(async function main() {
  const poolMaster = mysql.createPool({ host: HOST, port: PORT, user: USER, password: PASS, waitForConnections: true, connectionLimit: 3 });
  try {
    const [empresas] = await poolMaster.query(
      `SELECT id, nombre, db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag FROM ${MASTER}.empresas WHERE id IN (1,2) ORDER BY id`
    );
    for (const e of empresas) {
      const pw = decryptPassword(e, env);
      const tp = mysql.createPool({ host: e.db_host || HOST, port: Number(e.db_port || PORT), database: e.db_name, user: e.db_user, password: pw, waitForConnections: true, connectionLimit: 3, multipleStatements: true });
      try {
        await repairTenantDb(tp, e.id, e.nombre);
      } finally {
        try { await tp.end(); } catch {}
      }
    }
    console.log('\n✅ REPARACIÓN tenant 1 y 2 FINALIZADA.');
  } finally {
    await poolMaster.end();
  }
})();
