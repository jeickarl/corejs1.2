// Node script: Compara tablas tenant 1 vs tenant 2, estructura y nombres.
// Usa misma configuración MASTER_DB que .env (sin password, root, 127.0.0.1, core_master).
// Luego lista SHOW TABLES de cada tenant DB, y diferencia.
const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');
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

(async function main() {
  const pool = mysql.createPool({ host: HOST, port: PORT, user: USER, password: PASS, waitForConnections: true, connectionLimit: 2, decimalNumbers: true, dateStrings: true });
  try {
    const [empresas] = await pool.query(
      `SELECT id, nombre, db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag FROM ${MASTER}.empresas WHERE estado <> 'deleted' ORDER BY id`
    );
    console.log('\n=== EMPRESAS ===');
    console.table(empresas.map(e => ({ id: e.id, nombre: e.nombre, db: e.db_name, user: e.db_user })));

    const tenants = new Map();
    for (const e of empresas) {
      let password = '';
      if (e.db_password_enc && e.db_password_iv && e.db_password_tag) {
        try {
          const crypto = require('crypto');
          const rawKey = (env.MASTER_DB_KEY || 'CHANGE_ME').trim();
          const decoded = Buffer.from(rawKey, 'base64');
          const key = decoded.length === 32 ? decoded : crypto.createHash('sha256').update(rawKey).digest();
          const decipher = crypto.createDecipheriv('aes-256-gcm', key, Buffer.from(e.db_password_iv, 'base64'));
          decipher.setAuthTag(Buffer.from(e.db_password_tag, 'base64'));
          const dec = Buffer.concat([decipher.update(Buffer.from(e.db_password_enc, 'base64')), decipher.final()]);
          password = dec.toString('utf8');
        } catch (_) {}
      }
      const poolT = mysql.createPool({ host: e.db_host || HOST, port: Number(e.db_port || PORT), database: e.db_name, user: e.db_user, password: password, waitForConnections: true, connectionLimit: 2 });
      try {
        const [tbl] = await poolT.query(`SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'`);
        const names = tbl.map(r => Object.values(r)[0]).sort();
        tenants.set(e.id, { id: e.id, nombre: e.nombre, db: e.db_name, tables: names, pool: poolT });
        console.log(`\n  TENANT ${e.id} (${e.nombre}) ${names.length} tablas: ${names.join(', ')}`);
      } catch (err) {
        console.log(`  TENANT ${e.id} ERROR: ${err.message}`);
        tenants.set(e.id, { id: e.id, error: err.message });
      }
    }

    // Diferencia tablas tenant 1 vs tenant 2
    const t1 = tenants.get(1);
    const t2 = tenants.get(2);
    if (t1 && t2 && t1.tables && t2.tables) {
      const s1 = new Set(t1.tables);
      const s2 = new Set(t2.tables);
      console.log('\n=== DIFERENCIAS TABLAS tenant 1 vs tenant 2 ===');
      const only1 = t1.tables.filter(n => !s2.has(n));
      const only2 = t2.tables.filter(n => !s1.has(n));
      console.log(`Solo tenant 1 (${only1.length}): ${only1.join(', ') || '(ninguna)'}`);
      console.log(`Solo tenant 2 (${only2.length}): ${only2.join(', ') || '(ninguna)'}`);

      // Ahora SHOW CREATE TABLE de las tablas en común y veamos diferencias columnas.
      const common = t1.tables.filter(n => s2.has(n));
      console.log('\n=== DIFERENCIAS ESTRUCTURA (columnas/indices) tablas comunes ===');
      for (const t of common) {
        try {
          const [c1] = await t1.pool.query(`SHOW COLUMNS FROM \`${t}\``);
          const [c2] = await t2.pool.query(`SHOW COLUMNS FROM \`${t}\``);
          const cols1 = new Map(c1.map(r => [r.Field, r]));
          const cols2 = new Map(c2.map(r => [r.Field, r]));
          const missingIn2 = [...cols1.keys()].filter(k => !cols2.has(k));
          const missingIn1 = [...cols2.keys()].filter(k => !cols1.has(k));
          const diffType = [];
          for (const k of cols1.keys()) {
            if (cols2.has(k)) {
              const a = cols1.get(k), b = cols2.get(k);
              if (a.Type !== b.Type || a.Null !== b.Null || (a.Default ?? '') !== (b.Default ?? '')) {
                diffType.push(`${k}: [T1 Type=${a.Type} Null=${a.Null} Def=${a.Default ?? 'NULL'}] vs [T2 Type=${b.Type} Null=${b.Null} Def=${b.Default ?? 'NULL'}]`);
              }
            }
          }
          if (missingIn1.length || missingIn2.length || diffType.length) {
            console.log(`\nTABLA: ${t}`);
            if (missingIn2.length) console.log(`  Columnas faltantes en tenant 2 (están en T1): ${missingIn2.join(', ')}`);
            if (missingIn1.length) console.log(`  Columnas faltantes en tenant 1 (están en T2): ${missingIn1.join(', ')}`);
            if (diffType.length) console.log(`  Columnas con diferencias TIPO/NULL/DEFAULT:\n    - ${diffType.join('\n    - ')}`);
          }
        } catch (err) {
          console.log(`  TABLA ${t} ERR: ${err.message}`);
        }
      }

      // Índices
      console.log('\n=== DIFERENCIAS ÍNDICES ===');
      for (const t of common) {
        try {
          const [i1] = await t1.pool.query(`SHOW INDEX FROM \`${t}\``);
          const [i2] = await t2.pool.query(`SHOW INDEX FROM \`${t}\``);
          const key = r => `${r.Key_name}|${r.Seq_in_index}|${r.Column_name}|${r.Non_unique}|${r.Index_type}`;
          const sI1 = new Set(i1.map(key));
          const sI2 = new Set(i2.map(key));
          const onlyI1 = i1.filter(r => !sI2.has(key(r))).map(r => `${r.Key_name}[${r.Seq_in_index}] ${r.Column_name} UNIQ=${r.Non_unique===0}`);
          const onlyI2 = i2.filter(r => !sI1.has(key(r))).map(r => `${r.Key_name}[${r.Seq_in_index}] ${r.Column_name} UNIQ=${r.Non_unique===0}`);
          if (onlyI1.length || onlyI2.length) {
            console.log(`\nTABLA ${t}:`);
            if (onlyI2.length) console.log(`  Índices SOLO EN T1 (faltan T2): ${onlyI1.join(' ; ')}`);
            if (onlyI1.length) console.log(`  Índices SOLO EN T2 (faltan T1): ${onlyI2.join(' ; ')}`);
          }
        } catch (_) {}
      }
    }
    // cerrar pools tenants
    for (const t of tenants.values()) if (t.pool) await t.pool.end().catch(() => {});
  } finally {
    await pool.end().catch(() => {});
  }
})();
