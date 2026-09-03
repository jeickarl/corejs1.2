import mysql from 'mysql2/promise';

async function run() {
  try {
    const p = mysql.createPool({host:'localhost',user:'root',password:'',database:'core_master'});
    const [emps] = await p.query<any>('SELECT db_name FROM empresas');
    for (const e of emps) {
      try {
        const t = mysql.createPool({host:'localhost',user:'root',password:'',database:e.db_name});
        if (e.db_name === 'core_tenant_000002') {
          const [settings] = await t.query('SELECT * FROM settings');
          console.log('Settings:', settings);
        }
      } catch (err) {}
    }
  } catch (err) {
    console.error('Fatal error:', err.message);
  }
  process.exit(0);
}

run();
