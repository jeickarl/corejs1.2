// Script: Copia DATOS SEMILLA desde tenant 2 (Nexar Repair) HACIA tenant 1 (Empresa_Base)
// para que tenant 1 sea la plantilla base del sistema.
// SÓLO inserta en tablas DESTINO si COUNT == 0, NUNCA sobrescribe datos.
// Además copia físicamente archivos de logos del dir uploads/000002 -> uploads/000001

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

const SEED_TABLES = [
  { table: 'tenants', idCol: 'id' },
  { table: 'brands', idCol: 'id', remapTenantId: true },
  { table: 'device_categories', idCol: 'id' },
  { table: 'device_types', idCol: 'id' },
  { table: 'models', idCol: 'id' },
  { table: 'accessories_checklist', idCol: 'id' },
  { table: 'equipment_accessories', idCol: 'id' },
  { table: 'services', idCol: 'id' },
  { table: 'order_statuses', idCol: 'id' },
  { table: 'payment_methods', idCol: 'id' },
  { table: 'payment_method_accounts', idCol: 'id' },
  { table: 'transaction_categories', idCol: 'id' },
  { table: 'document_templates', idCol: 'id' },
  { table: 'document_fields', idCol: 'id' },
  { table: 'template_elements', idCol: 'id' },
  { table: 'whatsapp_templates', idCol: 'id' },
  { table: 'system_config', idCol: 'id' },
  { table: 'tenant_counters', idCol: 'id' },
  { table: 'company_config', idCol: 'id', setCompanyName: true },
  { table: 'company_settings', idCol: 'id', remapTenantId: true, setCompanyName: true },
  { table: 'users', idCol: 'id' },
];

function copyDirRecursiveSync(src, dest) {
  let copied = 0;
  if (!fs.existsSync(src)) return copied;
  try { fs.mkdirSync(dest, { recursive: true }); } catch { return copied; }
  const entries = fs.readdirSync(src, { withFileTypes: true });
  for (const entry of entries) {
    const s = path.join(src, entry.name);
    const d = path.join(dest, entry.name);
    if (entry.isDirectory()) {
      copied += copyDirRecursiveSync(s, d);
    } else {
      try {
        if (!fs.existsSync(d)) {
          fs.copyFileSync(s, d);
          copied++;
        }
      } catch {
      }
    }
  }
  return copied;
}

(async function main() {
  const pool = mysql.createPool({ host: HOST, port: PORT, user: USER, password: PASS, waitForConnections: true, connectionLimit: 3, namedPlaceholders: true, decimalNumbers: true, dateStrings: true });
  try {
    const [empresas] = await pool.query(
      `SELECT id, nombre, db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag FROM ${MASTER}.empresas WHERE id IN (1,2) ORDER BY id`
    );
    console.log('\n=== EMPRESAS A PROCESAR ===');
    console.table(empresas.map(e => ({ id: e.id, nombre: e.nombre, db: e.db_name, user: e.db_user })));

    const tenantData = new Map();
    for (const e of empresas) {
      const pw = decryptPassword(e, env);
      const tp = mysql.createPool({ host: e.db_host || HOST, port: Number(e.db_port || PORT), database: e.db_name, user: e.db_user, password: pw, waitForConnections: true, connectionLimit: 2, decimalNumbers: true, dateStrings: true, namedPlaceholders: true });
      tenantData.set(e.id, { id: e.id, nombre: e.nombre, db: e.db_name, pool: tp });
    }

    const T2 = tenantData.get(2); // fuente Nexar
    const T1 = tenantData.get(1); // destino Empresa_Base
    if (!T1 || !T2) {
      console.error('NO EXISTEN tenant 1 y/o tenant 2. Detener.');
      return;
    }

    console.log(`\n=== INICIANDO COPIA SEMILLA: ${T2.nombre} (id=2) -> ${T1.nombre} (id=1) ===\n`);
    const out = [];

    for (const t of SEED_TABLES) {
      try {
        // COUNT destino
        const [cntR] = await T1.pool.query(`SELECT COUNT(*) AS c FROM \`${t.table}\``);
        const cnt = Number(cntR?.[0]?.c ?? 0);
        if (cnt > 0) {
          out.push({ table: t.table, status: 'SKIP (datos existentes)', source: 0, inserted: 0, error: null });
          continue;
        }
        // SELECT source
        const [srcRows] = await T2.pool.query(`SELECT * FROM \`${t.table}\``);
        const list = Array.isArray(srcRows) ? srcRows : [];
        if (list.length === 0) {
          out.push({ table: t.table, status: 'SKIP (fuente vacía)', source: 0, inserted: 0, error: null });
          continue;
        }
        const cols = Object.keys(list[0]);
        const placeholders = list.map(() => `(${cols.map(() => '?').join(', ')})`).join(', ');
        const params = [];
        for (const r of list) {
          for (const k of cols) {
            let v = r[k];
            if (t.remapTenantId && k === 'tenant_id') v = 1;
            if (t.setCompanyName && k === 'company_name') v = String(T1.nombre);
            if (t.idCol === 'id' && k === 'id') v = null;
            params.push(v);
          }
        }
        const sql = `INSERT INTO \`${t.table}\` (${cols.map(c => `\`${c}\``).join(', ')}) VALUES ${placeholders}`;
        const [insR] = await T1.pool.query(sql, params);
        const inserted = Number(insR?.affectedRows ?? 0);
        out.push({ table: t.table, status: 'OK', source: list.length, inserted, error: null });
      } catch (err) {
        out.push({ table: t.table, status: 'ERROR', source: 0, inserted: 0, error: err?.message ?? String(err) });
      }
    }

    console.log('\n=== RESULTADOS COPIA DATOS ===');
    console.table(out);

    // Copia física uploads
    const baseDir = path.resolve(__dirname, 'apps', 'backend', 'uploads');
    const srcUp = path.join(baseDir, '000002');
    const dstUp = path.join(baseDir, '000001');
    let filesCopied = 0;
    try {
      filesCopied = copyDirRecursiveSync(srcUp, dstUp);
    } catch (err) {
      console.log(`\n❌ ERROR copia física logos: ${err.message}`);
    }
    console.log(`\n=== COPIA FÍSICA LOGOS: ${filesCopied} archivos nuevos copiados ===`);
    console.log(`  Origen : ${srcUp}`);
    console.log(`  Destino: ${dstUp}`);

    // Cerrar pools
    for (const td of tenantData.values()) try { await td.pool.end(); } catch {}
  } finally {
    await pool.end();
  }
  console.log('\n✅ SEEDING T1 desde T2 FINALIZADO.');
})();
