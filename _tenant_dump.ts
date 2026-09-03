import { createPool } from 'mysql2/promise';
import * as fs from 'fs';
import * as path from 'path';
import * as crypto from 'crypto';

const envPath = path.join(process.cwd(), 'apps', 'backend', '.env');
let env: Record<string, string> = {};
try {
  env = fs.readFileSync(envPath, 'utf8').split('\n').reduce<Record<string, string>>((o, line) => {
    const [k, ...rest] = line.split('=');
    if (k && !k.trim().startsWith('#')) o[k.trim()] = rest.join('=').trim();
    return o;
  }, {});
} catch {}
const HOST = env.MASTER_DB_HOST || '127.0.0.1';
const PORT = Number(env.MASTER_DB_PORT || 3306);
const MASTER = env.MASTER_DB_NAME || 'core_master';
const USER = env.MASTER_DB_USER || 'root';
const PASS = env.MASTER_DB_PASS || '';

function decryptMaster(encB64: string, ivB64: string, tagB64: string): string {
  const rawKey = (env.MASTER_DB_KEY || 'CHANGE_ME').trim();
  const decoded = Buffer.from(rawKey, 'base64');
  const key = decoded.length === 32 ? decoded : crypto.createHash('sha256').update(rawKey).digest();
  const ciphertext = Buffer.from(encB64, 'base64');
  const iv = Buffer.from(ivB64, 'base64');
  const tag = Buffer.from(tagB64, 'base64');
  const decipher = crypto.createDecipheriv('aes-256-gcm', key, iv);
  (decipher as any).setAuthTag(tag);
  return Buffer.concat([(decipher as any).update(ciphertext), (decipher as any).final()]).toString('utf8');
}

type Row = Record<string, any>;

(async function main() {
  const pool = createPool({ host: HOST, port: PORT, user: USER, password: PASS, waitForConnections: true, connectionLimit: 2, decimalNumbers: true, dateStrings: true });
  try {
    const [empresas] = await pool.query<Row[]>(
      `SELECT id, db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag FROM ${MASTER}.empresas WHERE estado <> 'deleted' ORDER BY id`
    );
    const opens = new Map<number, { id: number; pool: ReturnType<typeof createPool>; tables: string[] }>();
    for (const e of empresas) {
      const password = (e.db_password_enc && e.db_password_iv && e.db_password_tag)
        ? decryptMaster(e.db_password_enc, e.db_password_iv, e.db_password_tag) : '';
      const poolT = createPool({ host: e.db_host || HOST, port: Number(e.db_port || PORT), database: e.db_name, user: e.db_user, password, waitForConnections: true, connectionLimit: 2 });
      const [tables] = await poolT.query<Row[]>(`SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'`);
      opens.set(Number(e.id), { id: Number(e.id), pool: poolT, tables: tables.map(r => Object.values(r)[0] as string).sort() });
    }
    const t1 = opens.get(1)!;
    const t2 = opens.get(2)!;
    const out: any = {};
    out.allTables = t1.tables;
    // DDLs
    out.ddls = {};
    for (const t of t1.tables) {
      const [r] = await t1.pool.query<Row[]>(`SHOW CREATE TABLE \`${t}\``);
      let sql = String(r[0]['Create Table'] ?? '').replace(/\s+AUTO_INCREMENT=\d+/gi, '').replace(/\s+COLLATE=[\w_]+/gi, '');
      out.ddls[t] = sql;
    }
    // Missing cols T1 vs T2 (columns present in T2 but not in T1)
    out.missingCols = {};
    for (const t of t1.tables) {
      const [c1] = await t1.pool.query<Row[]>(`SHOW COLUMNS FROM \`${t}\``);
      const [c2] = await t2.pool.query<Row[]>(`SHOW COLUMNS FROM \`${t}\``);
      const s1 = new Set(c1.map(r => String(r.Field)));
      const miss = c2.filter(r => !s1.has(String(r.Field))).map(m => ({ Field: m.Field, Type: m.Type, Null: m.Null, Key: m.Key, Default: m.Default, Extra: m.Extra }));
      if (miss.length) out.missingCols[t] = miss;
    }
    // Missing idx T1 vs T2 (indices present in T2 but not in T1)
    out.missingIdx = {};
    for (const t of t1.tables) {
      try {
        const [i1] = await t1.pool.query<Row[]>(`SHOW INDEX FROM \`${t}\``);
        const [i2] = await t2.pool.query<Row[]>(`SHOW INDEX FROM \`${t}\``);
        const k = (r: Row) => `${r.Table}|${r.Key_name}|${r.Seq_in_index}|${r.Column_name}|${r.Non_unique}|${r.Index_type}`;
        const sI1 = new Set(i1.map(k));
        const inT2 = i2.filter(r => !sI1.has(k(r)));
        if (inT2.length) out.missingIdx[t] = inT2.map(r => ({ Key_name: r.Key_name, Seq_in_index: r.Seq_in_index, Column_name: r.Column_name, Non_unique: r.Non_unique, Index_type: r.Index_type, Unique: r.Non_unique === 0 }));
      } catch {}
    }
    // Seed data
    out.seed = {};
    const seedTables = [
      'brands','device_categories','device_types','services','order_statuses',
      'payment_methods','payment_method_accounts','system_config','tenant_counters',
      'company_config','company_settings','transaction_categories','document_templates',
      'whatsapp_templates','equipment_accessories','accessories_checklist'
    ];
    for (const t of seedTables) {
      try {
        const [rows] = await t1.pool.query<Row[]>(`SELECT * FROM \`${t}\``);
        out.seed[t] = rows;
      } catch (err: any) { out.seed[t + '__err'] = String(err?.message || err); }
    }
    // Logos paths
    out.logos = { brands: [] as any[], company: [] as any[], brandsT2: [] as any[], companyT2: [] as any[] };
    try { const [r] = await t1.pool.query<Row[]>(`SELECT id, name, logo_path, logo, COALESCE(NULLIF(logo_url,''),NULL) AS logo_url FROM brands`); out.logos.brands = r.filter(x => x.logo_path || x.logo || x.logo_url); } catch {}
    try { const [r] = await t1.pool.query<Row[]>(`SELECT id, company_logo, company_name, COALESCE(NULLIF(logo_url,''),NULL) AS logo_url FROM company_config`); out.logos.company = r.filter(x => x.company_logo || x.logo_url); } catch {}
    try { const [r] = await t2.pool.query<Row[]>(`SELECT id, name, logo_url FROM brands WHERE logo_url IS NOT NULL AND logo_url <> ''`); out.logos.brandsT2 = r; } catch {}
    try { const [r] = await t2.pool.query<Row[]>(`SELECT id, logo_url FROM company_config WHERE logo_url IS NOT NULL AND logo_url <> ''`); out.logos.companyT2 = r; } catch {}

    // 1st chunk: summary
    console.log('[SECTION:SUMMARY]');
    console.log(JSON.stringify({ tables: out.allTables, missingCols: Object.keys(out.missingCols), missingIdx: Object.keys(out.missingIdx), seedTables: Object.keys(out.seed) }, null, 2));
    console.log('\n[SECTION:MISSING_COLS_JSON]');
    console.log(JSON.stringify(out.missingCols, null, 2));
    console.log('\n[SECTION:MISSING_IDX_JSON]');
    console.log(JSON.stringify(out.missingIdx, null, 2));
    console.log('\n[SECTION:SEED_JSON]');
    console.log(JSON.stringify(out.seed, null, 2));
    console.log('\n[SECTION:LOGOS_JSON]');
    console.log(JSON.stringify(out.logos, null, 2));

    // Close
    for (const t of opens.values()) await t.pool.end().catch(() => {});
  } finally {
    await pool.end().catch(() => {});
  }
})();
