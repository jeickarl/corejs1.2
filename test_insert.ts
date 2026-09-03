import mysql from 'mysql2/promise';

async function run() {
  try {
    const pool = mysql.createPool({host:'localhost',user:'root',password:'',database:'core_master'});
    
    // First, let's just make sure work_orders schema is fully initialized
    const [emps] = await pool.query<any>('SELECT db_name FROM empresas WHERE id=2');
    const dbName = emps.length ? emps[0].db_name : null;
    if (!dbName) { console.log('No tenant 2'); return; }
    const tPool = mysql.createPool({host:'localhost',user:'root',password:'',database:dbName});
    
    const [clients] = await tPool.query<any>('SELECT id FROM clients LIMIT 1');
    const clientId = clients.length ? clients[0].id : null;
    const [types] = await tPool.query<any>('SELECT id FROM device_types LIMIT 1');
    const deviceTypeId = types.length ? types[0].id : null;
    console.log({clientId, deviceTypeId});
    
    if (clientId && deviceTypeId) {
      try {
        const [result] = await tPool.execute(
          `INSERT INTO work_orders (
            client_id, device_type_id, device_brand, device_model, device_password, serial_number,
            reported_issue, client_observations, status, priority, estimated_cost, advance_payment, payment_method, payment_reference,
            technician_notes, estimated_completion, created_at, updated_at
          )
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`,
          [clientId, deviceTypeId, 'brand', 'model', '', '123', 'issue', '', 'pending', 'medium', 0, 0, '', '', '', '']
        );
        console.log('Success:', result);
        const [warnings] = await tPool.query('SHOW WARNINGS');
        console.log('Warnings:', warnings);
      } catch (err) {
        console.log('Insert Error:', err.message);
      }
    }
  } catch(e) {
    console.error('Error:', e);
  }
  process.exit(0);
}
run();
