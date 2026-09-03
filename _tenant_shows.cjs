// Extrae SHOW CREATE TABLE completo de T1 = tenant 1 (base) para poder generar DDLs faltantes en repairSchema.dao.ts
// También muestra valores de columnas extra en T2 vs T1.
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

function decryptMaster(encB64, ivB64, tagB64) {
  const rawKey = (env.MASTER_DB_KEY || 'CHANGE_ME').trim();
  const decoded = Buffer.from(rawKey, 'base64');
  const key = decoded.length === 32 ? decoded : crypto.createHash('sha256').update(rawKey).digest();
  const ciphertext = Buffer.from(encB64, 'base64');
  const iv = Buffer.from(ivB64, 'base64');
  const tag = Buffer.from(tagB64, 'base64');
  const decipher = crypto.createDecipheriv('aes-256-gcm', key, iv);
  decipher.setAuthTag(tag);
  return Buffer.concat([decipher.update(ciphertext), decipher.final()]).toString('utf8');
}

(async function main() {
  const pool = mysql.createPool({ host: HOST, port: PORT, user: USER, password: PASS, waitForConnections: true, connectionLimit: 2, decimalNumbers: true, dateStrings: true });
  try {
    const [empresas] = await pool.query(
      `SELECT id, db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag FROM ${MASTER}.empresas WHERE estado <> 'deleted' ORDER BY id`
    );
    const opens = new Map();
    for (const e of empresas) {
      const password = (e.db_password_enc && e.db_password_iv && e.db_password_tag)
        ? decryptMaster(e.db_password_enc, e.db_password_iv, e.db_password_tag) : '';
      const poolT = mysql.createPool({ host: e.db_host || HOST, port: Number(e.db_port || PORT), database: e.db_name, user: e.db_user, password: password, waitForConnections: true, connectionLimit: 2 });
      const [tables] = await poolT.query(`SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'`);
      opens.set(e.id, { id: e.id, db: e.db_name, pool: poolT, tables: tables.map(r => Object.values(r)[0]).sort() });
    }

    const t1 = opens.get(1); // base
    // List of existing steps in repairSchema ddls() (extract from previous read)
    const existingSteps = [
      'inventory_products','inventory_movements','suppliers','purchase_orders','supplier_payments',
      'purchase_receipts','purchase_receipt_items','device_categories','services','order_statuses',
      'technical_reports','work_order_services','dashboard_notes','order_status_history',
      'notifications','user_notifications'
    ];
    const existing = new Set(existingSteps);
    const missingInRepair = t1.tables.filter(n => !existing.has(n));
    console.log(`\n=== TABLAS FALTANTES EN repairSchema.ddls() (deben agregarse): ${missingInRepair.length} ===`);
    console.log(missingInRepair.join(', '));

    // Imprimir SHOW CREATE TABLE de cada tabla faltante en forma de SQL listo para pastear
    console.log(`\n=== SHOW CREATE TABLE TABLAS FALTANTES (para DDL) ===`);
    for (const t of missingInRepair) {
      const [r] = await t1.pool.query(`SHOW CREATE TABLE \`${t}\``);
      console.log(`\n-- DDL: ${t}`);
      console.log((r[0]['Create Table'] ?? '').replace(/AUTO_INCREMENT=\d+ ?/, '') + ';');
    }

    // Imprimir SHOW CREATE TABLE de las tablas con columnas faltantes en T1 (para ensureColumns)
    console.log(`\n=== TABLAS CON COLUMNAS FALTANTES EN T1 = Empresa_Base (deben agregarse ALTER en ensureColumns) ===`);
    const t2 = opens.get(2);
    for (const t of t1.tables) {
      const [c1] = await t1.pool.query(`SHOW COLUMNS FROM \`${t}\``);
      const [c2] = await t2.pool.query(`SHOW COLUMNS FROM \`${t}\``);
      const s1 = new Set(c1.map(r => r.Field));
      const missing = c2.filter(r => !s1.has(r.Field));
      if (missing.length) {
        console.log(`\n-- COLUMNS FALTANTES EN T1 tabla ${t} (están en T2):`);
        for (const m of missing) {
          const def = (m.Default !== null && m.Default !== undefined) ? `DEFAULT ${m.Default}` : '';
          console.log(`  ${m.Field} ${m.Type} ${m.Null === 'YES' ? 'NULL' : 'NOT NULL'} ${def}`);
        }
      }
    }

    // Data seed a copiar desde T1: brands (logos), categories, services, device_types, payment_methods, payment_method_accounts, system_config, counters, document_templates, whatsapp_templates...
    console.log(`\n=== SEED TABLAS DE T1 = Empresa_Base (datos ejemplo para sembrar nueva empresa) ===`);
    const seedTables = ['brands','device_categories','device_types','services','payment_methods','payment_method_accounts','system_config','tenant_counters','company_config','company_settings'];
    for (const t of seedTables) {
      try {
        const [rows] = await t1.pool.query(`SELECT * FROM \`${t}\``);
        console.log(`\n-- ${t} (${rows.length} rows):`);
        console.log(JSON.stringify(rows, null, 2).slice(0, 4000));
      } catch (err) {
        console.log(`\n-- ${t} ERR: ${err.message}`);
      }
    }

    // cerrar
    for (const t of opens.values()) await t.pool.end().catch(() => {});
  } finally {
    await pool.end().catch(() => {});
  }
})();
