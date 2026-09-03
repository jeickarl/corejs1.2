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
(async () => {
  const mp = mysql.createPool({ host: HOST, port: PORT, user: USER, password: PASS, waitForConnections: true });
  try {
    const [emps] = await mp.query(`SELECT id, nombre, db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag FROM ${MASTER}.empresas WHERE id IN (1,2) ORDER BY id`);
    const t1e = emps.find(e => e.id === 1);
    const t2e = emps.find(e => e.id === 2);
    const t1p = mysql.createPool({ host: t1e.db_host || HOST, port: Number(t1e.db_port || PORT), database: t1e.db_name, user: t1e.db_user, password: decryptPassword(t1e, env) });
    const t2p = mysql.createPool({ host: t2e.db_host || HOST, port: Number(t2e.db_port || PORT), database: t2e.db_name, user: t2e.db_user, password: decryptPassword(t2e, env) });
    try {
      const [[{ cnt: cnt1 }]] = await t1p.query('SELECT COUNT(*) as cnt FROM company_config');
      console.log(`T1 company_config count: ${cnt1}`);
      if (cnt1 === 0) {
        const [rows] = await t2p.query('SELECT * FROM company_config LIMIT 1');
        const row = rows[0];
        if (row) {
          const cols = Object.keys(row);
          const values = cols.map(c => {
            if (c === 'id') { return 1; }
            if (c === 'company_name') { return String(t1e.nombre); }
            return row[c];
          });
          const sql = `INSERT INTO company_config (${cols.map(c => '`'+c+'`').join(', ')}) VALUES (${cols.map(() => '?').join(', ')})`;
          await t1p.query(sql, values);
          console.log('✅ company_config copiado con id=1');
        }
      } else {
        console.log('ℹ️  company_config ya tiene datos, no se toca.');
      }

      // Copiar físicamente logos si la carpeta fuente existe y no está vacía
      const srcDir = path.resolve(__dirname, 'apps', 'backend', 'uploads', '000002');
      const dstDir = path.resolve(__dirname, 'apps', 'backend', 'uploads', '000001');
      function copyRecursive(src, dst, acc) {
        if (!fs.existsSync(src)) return acc;
        try { fs.mkdirSync(dst, { recursive: true }); } catch { return acc; }
        const entries = fs.readdirSync(src, { withFileTypes: true });
        for (const e of entries) {
          const s = path.join(src, e.name); const d = path.join(dst, e.name);
          if (e.isDirectory()) copyRecursive(s, d, acc);
          else if (!fs.existsSync(d)) { try { fs.copyFileSync(s, d); acc.n++; } catch {} }
        }
        return acc;
      }
      const acc = copyRecursive(srcDir, dstDir, { n: 0 });
      console.log(`\n📁 Archivos nuevos copiados de logos: ${acc.n}`);
      console.log(`  Origen: ${srcDir}`);
      console.log(`  Destino: ${dstDir}`);
    } finally { try { await t1p.end(); await t2p.end(); } catch {} }
  } finally { await mp.end(); }
  console.log('\n✅ Finalizado.');
})();
