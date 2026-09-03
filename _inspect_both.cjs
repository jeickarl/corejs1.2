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
    for (const e of emps) {
      const tp = mysql.createPool({ host: e.db_host || HOST, port: Number(e.db_port || PORT), database: e.db_name, user: e.db_user, password: decryptPassword(e, env) });
      console.log(`\n======== TENANT ${e.id} (${e.nombre}) ========`);
      try {
        for (const tbl of ['order_parts', 'payment_methods', 'invoice_items']) {
          try {
            const [cols] = await tp.query(`SHOW COLUMNS FROM \`${tbl}\``);
            const [[{ cnt }]] = await tp.query(`SELECT COUNT(*) as cnt FROM \`${tbl}\``);
            console.log(`\n  TABLA ${tbl} (${cnt} filas):`);
            cols.forEach(c => process.stdout.write(`    ${c.Field}[${c.Type} ${c.Null === 'NO' ? 'NOT NULL' : 'NULL'} ${c.Default ? 'DEFAULT ' + c.Default : ''}] `));
            console.log();
          } catch (err) {
            console.log(`  TABLA ${tbl} ERROR: ${err.message}`);
          }
        }
      } finally { try { await tp.end(); } catch {} }
    }
  } finally { await mp.end(); }
})();
