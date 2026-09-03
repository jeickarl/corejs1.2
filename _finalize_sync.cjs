// Script FINAL: soluciona las 6 tablas que aun tienen diferencias luego del repair inicial.
// Usa chequeo SELECT * FROM col LIMIT 1 o information_schema ANTES de ejecutar ALTER.
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

async function colExists(pool, table, col) {
  try {
    const [rows] = await pool.query(`SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?`, [table, col]);
    return Array.isArray(rows) && rows.length > 0;
  } catch {
    try { await pool.query(`SELECT \`${col}\` FROM \`${table}\` LIMIT 1`); return true; } catch { return false; }
  }
}

async function execSafe(pool, sql, step) {
  try { await pool.query(sql); return { step, ok: true, error: null }; }
  catch (e) { return { step, ok: false, error: e.message }; }
}

async function syncTable(pool, tableName, fixes) {
  console.log(`  \nSYNC ${tableName}`);
  const out = [];
  for (const f of fixes) {
    if (f.type === 'ADD' && !(await colExists(pool, tableName, f.col))) {
      out.push(await execSafe(pool, f.sql, `${tableName}.ADD ${f.col}`));
    } else if (f.type === 'MODIFY') {
      out.push(await execSafe(pool, f.sql, `${tableName}.MODIFY ${f.col}`));
    } else if (f.type === 'INDEX') {
      try {
        const [rows] = await pool.query(`SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?`, [tableName, f.name]);
        if (!Array.isArray(rows) || rows.length === 0) {
          out.push(await execSafe(pool, f.sql, `${tableName}.INDEX ${f.name}`));
        } else {
          out.push({ step: `${tableName}.INDEX ${f.name}`, ok: true, error: 'existe' });
        }
      } catch (e) {
        out.push(await execSafe(pool, f.sql, `${tableName}.INDEX ${f.name}`));
      }
    } else if (f.type === 'ADD' && (await colExists(pool, tableName, f.col))) {
      out.push({ step: `${tableName}.ADD ${f.col}`, ok: true, error: 'ya existe' });
    }
  }
  const oks = out.filter(r => r.ok).length;
  const fails = out.filter(r => !r.ok && r.error !== 'ya existe' && r.error !== 'existe').length;
  console.log(`    ok=${oks}  fails=${fails}`);
  for (const r of out) if (!r.ok && r.error !== 'ya existe' && r.error !== 'existe') console.log(`    ❌ ${r.step}: ${r.error}`);
}

const BRANDS_FIXES = [
  { type: 'ADD', col: 'logo_url', sql: 'ALTER TABLE brands ADD COLUMN logo_url VARCHAR(255) NULL AFTER logo' },
  { type: 'MODIFY', col: 'logo_url', sql: 'ALTER TABLE brands MODIFY COLUMN logo_url VARCHAR(255) NOT NULL DEFAULT \'\'' },
];

const COMPANY_CONFIG_FIXES = [
  { type: 'ADD', col: 'logo_url', sql: 'ALTER TABLE company_config ADD COLUMN logo_url VARCHAR(255) NULL AFTER company_website' },
  { type: 'MODIFY', col: 'logo_url', sql: 'ALTER TABLE company_config MODIFY COLUMN logo_url VARCHAR(255) NOT NULL DEFAULT \'\'' },
];

const INVOICE_ITEMS_FIXES = [
  { type: 'ADD', col: 'product_id', sql: 'ALTER TABLE invoice_items ADD COLUMN product_id INT(11) NULL AFTER description' },
  { type: 'MODIFY', col: 'product_id', sql: 'ALTER TABLE invoice_items MODIFY COLUMN product_id INT(10) UNSIGNED NULL' },
];

const ORDER_PARTS_FIXES = [
  { type: 'ADD', col: 'tenant_id', sql: 'ALTER TABLE order_parts ADD COLUMN tenant_id INT(11) NULL AFTER updated_at' },
];

const PAYMENT_METHOD_ACCOUNTS_FIXES = [
  { type: 'ADD', col: 'payment_method_id', sql: 'ALTER TABLE payment_method_accounts ADD COLUMN payment_method_id INT(11) NULL AFTER id' },
  { type: 'MODIFY', col: 'payment_method_id', sql: 'ALTER TABLE payment_method_accounts MODIFY COLUMN payment_method_id INT(10) UNSIGNED NULL' },
  { type: 'ADD', col: 'account_type', sql: 'ALTER TABLE payment_method_accounts ADD COLUMN account_type VARCHAR(50) NULL AFTER account_name' },
  { type: 'MODIFY', col: 'account_type', sql: 'ALTER TABLE payment_method_accounts MODIFY COLUMN account_type VARCHAR(50) NOT NULL DEFAULT \'\'' },
];

const PAYMENT_METHODS_FIXES = [
  { type: 'ADD', col: 'is_default', sql: 'ALTER TABLE payment_methods ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order' },
  { type: 'ADD', col: 'is_active', sql: 'ALTER TABLE payment_methods ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_default' },
  { type: 'ADD', col: 'created_at', sql: 'ALTER TABLE payment_methods ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active' },
  { type: 'ADD', col: 'updated_at', sql: 'ALTER TABLE payment_methods ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at' },
];

const WORK_ORDERS_FIXES = [
  { type: 'ADD', col: 'verification_code', sql: 'ALTER TABLE work_orders ADD COLUMN verification_code VARCHAR(16) NULL AFTER order_number' },
  { type: 'MODIFY', col: 'verification_code', sql: 'ALTER TABLE work_orders MODIFY COLUMN verification_code VARCHAR(16) NULL' },
  { type: 'ADD', col: 'status', sql: 'ALTER TABLE work_orders ADD COLUMN status VARCHAR(64) NOT NULL DEFAULT \'pending\'' },
  { type: 'MODIFY', col: 'status', sql: 'ALTER TABLE work_orders MODIFY COLUMN status VARCHAR(64) NOT NULL DEFAULT \'pending\'' },
  { type: 'ADD', col: 'approval_status', sql: 'ALTER TABLE work_orders ADD COLUMN approval_status VARCHAR(32) NULL AFTER verification_code' },
  { type: 'ADD', col: 'approval_signature_path', sql: 'ALTER TABLE work_orders ADD COLUMN approval_signature_path VARCHAR(255) NULL AFTER customer_signature' },
  { type: 'ADD', col: 'approval_comment', sql: 'ALTER TABLE work_orders ADD COLUMN approval_comment TEXT NULL AFTER approval_signature_path' },
  { type: 'ADD', col: 'approval_signature', sql: 'ALTER TABLE work_orders ADD COLUMN approval_signature TEXT NULL AFTER approval_comment' },
  { type: 'ADD', col: 'approved_at', sql: 'ALTER TABLE work_orders ADD COLUMN approved_at DATETIME NULL AFTER approval_signature' },
  { type: 'ADD', col: 'approved_quote_amount', sql: 'ALTER TABLE work_orders ADD COLUMN approved_quote_amount DECIMAL(12,2) NULL AFTER approved_at' },
  { type: 'MODIFY', col: 'approved_quote_amount', sql: 'ALTER TABLE work_orders MODIFY COLUMN approved_quote_amount DECIMAL(10,2) NULL' },
];

const USER_NOTIFICATIONS_FIXES = [
  { type: 'ADD', col: 'is_read', sql: 'ALTER TABLE user_notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER notification_id' },
  { type: 'ADD', col: 'created_at', sql: 'ALTER TABLE user_notifications ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_read' },
  { type: 'INDEX', name: 'idx_user_read', sql: 'CREATE INDEX idx_user_read ON user_notifications (user_id, is_read)' },
  { type: 'INDEX', name: 'idx_notification_id', sql: 'CREATE INDEX idx_notification_id ON user_notifications (notification_id)' },
];

(async function main() {
  const poolMaster = mysql.createPool({ host: HOST, port: PORT, user: USER, password: PASS, waitForConnections: true, connectionLimit: 3 });
  try {
    const [empresas] = await poolMaster.query(
      `SELECT id, nombre, db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag FROM ${MASTER}.empresas WHERE id IN (1,2) ORDER BY id`
    );
    for (const e of empresas) {
      const pw = decryptPassword(e, env);
      const tp = mysql.createPool({ host: e.db_host || HOST, port: Number(e.db_port || PORT), database: e.db_name, user: e.db_user, password: pw, waitForConnections: true, connectionLimit: 3 });
      console.log(`\n======== TENANT ${e.id} (${e.nombre}) ========`);
      try {
        await syncTable(tp, 'brands', BRANDS_FIXES);
        await syncTable(tp, 'company_config', COMPANY_CONFIG_FIXES);
        await syncTable(tp, 'invoice_items', INVOICE_ITEMS_FIXES);
        await syncTable(tp, 'order_parts', ORDER_PARTS_FIXES);
        await syncTable(tp, 'payment_method_accounts', PAYMENT_METHOD_ACCOUNTS_FIXES);
        await syncTable(tp, 'payment_methods', PAYMENT_METHODS_FIXES);
        await syncTable(tp, 'work_orders', WORK_ORDERS_FIXES);
        await syncTable(tp, 'user_notifications', USER_NOTIFICATIONS_FIXES);
      } finally {
        try { await tp.end(); } catch {}
      }
    }
    console.log('\n✅ SINCRONIZACIÓN FINAL FINALIZADA. Vuelve a correr _compare_tenants.cjs para validar 0 diferencias.');
  } finally {
    await poolMaster.end();
  }
})();
