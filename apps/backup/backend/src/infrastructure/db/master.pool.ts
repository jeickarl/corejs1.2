import { createPool } from 'mysql2/promise';

export type MasterDbPool = ReturnType<typeof createPool>;

export function createMasterPool() {
  const host = process.env.MASTER_DB_HOST ?? 'localhost';
  const port = Number(process.env.MASTER_DB_PORT ?? 3306);
  const database = process.env.MASTER_DB_NAME ?? '';
  const user = process.env.MASTER_DB_USER ?? '';
  const password = process.env.MASTER_DB_PASS ?? '';

  return createPool({
    host,
    port,
    database,
    user,
    password,
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0,
    decimalNumbers: true,
    namedPlaceholders: true,
  });
}

