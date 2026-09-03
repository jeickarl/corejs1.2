import mysql from 'mysql2/promise';

async function run() {
  try {
    const p = mysql.createPool({host:'localhost',user:'root',password:'',database:'core_master'});
    const [emps] = await p.query<any>('SELECT db_name FROM empresas');
    for (const e of emps) {
      console.log('Migrating', e.db_name);
      try {
        const t = mysql.createPool({host:'localhost',user:'root',password:'',database:e.db_name});
        const [rows] = await t.query<any>("SHOW COLUMNS FROM work_orders LIKE 'order_number'");
        const type = String(rows?.[0]?.Type ?? '').toLowerCase();
        if (type.includes('int')) {
          console.log(` - Column is ${type}, altering to VARCHAR(64)`);
          await t.query("ALTER TABLE work_orders MODIFY COLUMN order_number VARCHAR(64) NULL");
          await t.query("UPDATE work_orders SET order_number = CONCAT('WO-', id) WHERE order_number = '0' OR order_number IS NULL OR order_number = ''");
          console.log(` - Done for ${e.db_name}`);
        } else {
          console.log(` - Column is ${type}, already migrated.`);
        }
      } catch (err) {
        console.error(` - Error for ${e.db_name}:`, err.message);
      }
    }
  } catch (err) {
    console.error('Fatal error:', err.message);
  }
  process.exit(0);
}

run();
