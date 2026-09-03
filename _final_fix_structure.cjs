// Fix FINAL basado en estructura REAL detectada
const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const envPath = path.join(__dirname, 'apps', 'backend', '.env');
let env = {};
try { env = fs.readFileSync(envPath, 'utf8').split('\n').reduce((o, line) => { const [k, ...rest] = line.split('='); if (k && !k.trim().startsWith('#')) o[k.trim()] = rest.join('=').trim(); return o; }, {}); } catch (_) {}
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
    return Buffer.concat([decipher.update(Buffer.from(e.db_password_enc, 'base64')), decipher.final()]).toString('utf8');
  } catch { return ''; }
}
function colExistsSync(cols, name) { return cols.some(c => c.Field === name); }
async function applyAlters(pool, tenantId, nombre) {
  console.log(`\n======== TENANT ${tenantId} (${nombre}) AJUSTES ========`);
  const alters = [];
  for (const table of ['order_parts', 'payment_methods', 'brands', 'company_config']) {
    const [cols] = await pool.query(`SHOW COLUMNS FROM \`${table}\``);
    if (table === 'order_parts' && !colExistsSync(cols, 'tenant_id')) {
      alters.push({ table, sql: 'ALTER TABLE order_parts ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id', step: 'order_parts.add_tenant_id' });
    }
    if (table === 'payment_methods') {
      if (!colExistsSync(cols, 'is_default')) alters.push({ table, sql: 'ALTER TABLE payment_methods ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER tenant_id', step: 'payment_methods.is_default' });
      const [cols2] = await pool.query(`SHOW COLUMNS FROM \`payment_methods\``);
      if (!colExistsSync(cols2, 'is_active')) alters.push({ table, sql: 'ALTER TABLE payment_methods ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_default', step: 'payment_methods.is_active' });
      const [cols3] = await pool.query(`SHOW COLUMNS FROM \`payment_methods\``);
      if (!colExistsSync(cols3, 'created_at')) alters.push({ table, sql: 'ALTER TABLE payment_methods ADD COLUMN created_at DATETIME NULL AFTER is_active', step: 'payment_methods.created_at' });
      const [cols4] = await pool.query(`SHOW COLUMNS FROM \`payment_methods\``);
      if (!colExistsSync(cols4, 'updated_at')) alters.push({ table, sql: 'ALTER TABLE payment_methods ADD COLUMN updated_at DATETIME NULL AFTER created_at', step: 'payment_methods.updated_at' });
    }
    if (table === 'brands') {
      if (!colExistsSync(cols, 'logo_url')) alters.push({ table, sql: 'ALTER TABLE brands ADD COLUMN logo_url VARCHAR(255) NULL AFTER logo', step: 'brands.logo_url_add' });
    }
    if (table === 'company_config') {
      if (!colExistsSync(cols, 'logo_url')) alters.push({ table, sql: 'ALTER TABLE company_config ADD COLUMN logo_url VARCHAR(255) NULL AFTER company_website', step: 'company_config.logo_url_add' });
    }
  }
  let ok = 0, fail = 0;
  for (const a of alters) {
    try { await pool.query(a.sql); ok++; console.log(`  ✅ ${a.step}`); }
    catch (e) { fail++; console.log(`  ❌ ${a.step}: ${e.message}`); }
  }
  if (alters.length === 0) console.log('  (sin cambios requeridos, estructura alineada)');
  return { ok, fail };
}
(async () => {
  const mp = mysql.createPool({ host: HOST, port: PORT, user: USER, password: PASS, waitForConnections: true });
  try {
    const [emps] = await mp.query(`SELECT id, nombre, db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag FROM ${MASTER}.empresas WHERE id IN (1,2) ORDER BY id`);
    for (const e of emps) {
      const tp = mysql.createPool({ host: e.db_host || HOST, port: Number(e.db_port || PORT), database: e.db_name, user: e.db_user, password: decryptPassword(e, env) });
      try {
        await applyAlters(tp, e.id, e.nombre);
      } finally { try { await tp.end(); } catch {} }
    }
    console.log('\n✅ AJUSTES FINALES COMPLETADOS. Ejecutar `node _compare_tenants.cjs` para confirmar 0 diferencias.');
  } finally { await mp.end(); }
})();
