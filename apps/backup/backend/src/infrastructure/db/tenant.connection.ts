import { createConnection } from 'mysql2/promise';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from './master.pool';
import { decryptMaster } from '../crypto/masterCrypto';

export async function createTenantConnection(
  masterPool: MasterDbPool,
  empresaId: number,
) {
  let rows: RowDataPacket[] = [];
  try {
    const [r] = await masterPool.query<RowDataPacket[]>(
      `
      SELECT db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag
      FROM empresas
      WHERE id = ?
      LIMIT 1
      `,
      [empresaId],
    );
    rows = r ?? [];
  } catch {
    const [r] = await masterPool.query<RowDataPacket[]>(
      `
      SELECT db_host, db_port, db_name, db_user, db_password
      FROM empresas
      WHERE id = ?
      LIMIT 1
      `,
      [empresaId],
    );
    rows = r ?? [];
  }
  const r = rows?.[0];
  if (!r) {
    throw new Error('Empresa no encontrada');
  }

  const host = String(r.db_host ?? 'localhost');
  const port = Number(r.db_port ?? 3306);
  const database = String(r.db_name ?? '');
  const user = String(r.db_user ?? '');
  const plainPassword = String(r.db_password ?? '');
  let password = '';
  if (r.db_password_enc && r.db_password_iv && r.db_password_tag) {
    try {
      password = decryptMaster(
        String(r.db_password_enc),
        String(r.db_password_iv),
        String(r.db_password_tag),
      );
    } catch {
      password = plainPassword;
      if (!password) {
        password = String(r.db_password_enc ?? '');
      }
    }
  } else if (plainPassword) {
    password = plainPassword;
  }

  try {
    return await createConnection({
      host,
      port,
      database,
      user,
      password,
    });
  } catch (e) {
    const anyE = e as { code?: unknown; message?: unknown };
    const code = typeof anyE?.code === 'string' ? anyE.code : 'UNKNOWN';
    const msg = typeof anyE?.message === 'string' ? anyE.message : 'Error desconocido';
    if (code === 'ER_ACCESS_DENIED_ERROR' && password) {
      try {
        return await createConnection({
          host,
          port,
          database,
          user,
          password: '',
        });
      } catch (e2) {
        const anyE2 = e2 as { code?: unknown; message?: unknown };
        const code2 = typeof anyE2?.code === 'string' ? anyE2.code : 'UNKNOWN';
        const msg2 = typeof anyE2?.message === 'string' ? anyE2.message : 'Error desconocido';
        throw new Error(
          `TENANT_DB_CONNECT_FAILED (${code}/${code2}): No se pudo conectar a ${user}@${host}:${port}/${database}. ${msg} | password vacío: ${msg2}`,
        );
      }
    }
    throw new Error(
      `TENANT_DB_CONNECT_FAILED (${code}): No se pudo conectar a ${user}@${host}:${port}/${database}. ${msg}`,
    );
  }
}
