// Escribe: DDLs completos + Columnas faltantes + Seed data en JSON separados para facilitar parseo manual.
// Salida directa a stdout.
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
    const t1 = opens.get(1);
    const t2 = opens.get(2);
    const all = t1.tables;
    console.log('\n\n[SECTION:ALL_TABLES]');
    console.log(JSON.stringify(all));

    console.log('\n\n[SECTION:DDL_ALL_TABLES_T1]');
    for (const t of all) {
      const [r] = await t1.pool.query(`SHOW CREATE TABLE \`${t}\``);
      console.log(`\n--DDL:${t}--`);
      // Strip AUTO_INCREMENT=NNN + ENGINE strict + COLLATE extras - keep IF NOT EXISTS
      let sql = (r[0]['Create Table'] ?? '').replace(/\s+AUTO_INCREMENT=\d+/g, '');
      // Convert COLLATE=utf8mb4_* into a safe default (no strict collation)
      sql = sql.replace(/\s+COLLATE=[\w_]+/gi, '');
      console.log(sql);
    }

    console.log('\n\n[SECTION:MISSING_COLS_T1_VS_T2]');
    const missingPerTable = {};
    for (const t of all) {
      const [c1] = await t1.pool.query(`SHOW COLUMNS FROM \`${t}\``);
      const [c2] = await t2.pool.query(`SHOW COLUMNS FROM \`${t}\``);
      const s1 = new Set(c1.map(r => r.Field));
      const missing = c2.filter(r => !s1.has(r.Field));
      if (missing.length) missingPerTable[t] = missing.map(m => ({ Field: m.Field, Type: m.Type, Null: m.Null, Key: m.Key, Default: m.Default, Extra: m.Extra }));
    }
    console.log(JSON.stringify(missingPerTable, null, 2));

    console.log('\n\n[SECTION:MISSING_INDEXES_T1_VS_T2]');
    const idxPerTable = {};
    for (const t of all) {
      try {
        const [i1] = await t1.pool.query(`SHOW INDEX FROM \`${t}\``);
        const [i2] = await t2.pool.query(`SHOW INDEX FROM \`${t}\``);
        const k = r => `${r.Table}|${r.Key_name}|${r.Seq_in_index}|${r.Column_name}|${r.Non_unique}|${r.Index_type}`;
        const sI1 = new Set(i1.map(k));
        const sI2 = new Set(i2.map(k));
        const inT2NotT1 = i2.filter(r => !sI1.has(k(r)));
        if (inT2NotT1.length) idxPerTable[t] = inT2NotT1.map(r => ({ Key_name: r.Key_name, Seq_in_index: r.Seq_in_index, Column_name: r.Column_name, Non_unique: r.Non_unique, Index_type: r.Index_type, Unique: r.Non_unique === 0 }));
      } catch (_) {}
    }
    console.log(JSON.stringify(idxPerTable, null, 2));

    console.log('\n\n[SECTION:SEED_FROM_T1]');
    const seed = {};
    const seedTables = [
      'brands','device_categories','device_types','services','order_statuses',
      'payment_methods','payment_method_accounts','system_config','tenant_counters',
      'company_config','company_settings','transaction_categories','document_templates',
      'whatsapp_templates','equipment_accessories','accessories_checklist','users'
    ];
    for (const t of seedTables) {
      try {
        const [rows] = await t1.pool.query(`SELECT * FROM \`${t}\``);
        seed[t] = rows;
      } catch (err) { seed[t + '__err'] = err.message; }
    }
    console.log(JSON.stringify(seed, null, 2));

    console.log('\n\n[SECTION:LOGO_PATHS_T1]');
    const logos = { brands: [], company: [] };
    try { const [r] = await t1.pool.query(`SELECT id, name, logo_path, logo, logo_url FROM brands WHERE logo_path IS NOT NULL OR logo IS NOT NULL OR logo_url IS NOT NULL`); logos.brands = r; } catch {}
    try { const [r] = await t1.pool.query(`SELECT id, company_logo, company_name FROM company_config WHERE company_logo IS NOT NULL`); logos.company = r; } catch {}
    try { const [r] = await t2.pool.query(`SELECT id, logo_url FROM brands WHERE logo_url IS NOT NULL`); logos.brands_t2_logourl = r; } catch {}
    try { const [r] = await t2.pool.query(`SELECT id, logo_url FROM company_config WHERE logo_url IS NOT NULL`); logos.company_t2_logourl = r; } catch {}
    console.log(JSON.stringify(logos, null, 2));

    for (const t of opens.values()) await t.pool.end().catch(() => {});
  } finally {
    await pool.end().catch(() => {});
  }
})();
