import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

export type SuperAdminTenantHealthRow = {
  id: number;
  companyName: string;
  status: string;
  dbHost: string;
  dbPort: number;
  dbName: string;
  dbUser: string;
  ok: boolean;
  error: string | null;
};

@Injectable()
export class HealthDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly pool: MasterDbPool) {}

  async tenantsHealth(): Promise<SuperAdminTenantHealthRow[]> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT id, nombre, estado, db_host, db_port, db_name, db_user
      FROM empresas
      WHERE estado <> 'deleted'
      ORDER BY id ASC
      LIMIT 500
      `,
    );

    const out: SuperAdminTenantHealthRow[] = [];
    for (const r of rows ?? []) {
      const id = Number(r.id ?? 0);
      if (!id) continue;
      let ok = false;
      let error: string | null = null;
      try {
        const conn = await createTenantConnection(this.pool, id);
        try {
          await conn.query('SELECT 1');
          ok = true;
        } finally {
          await conn.end();
        }
      } catch (e) {
        ok = false;
        error = e instanceof Error ? e.message : 'Error';
      }

      out.push({
        id,
        companyName: String(r.nombre ?? ''),
        status: String(r.estado ?? ''),
        dbHost: String(r.db_host ?? ''),
        dbPort: Number(r.db_port ?? 0),
        dbName: String(r.db_name ?? ''),
        dbUser: String(r.db_user ?? ''),
        ok,
        error,
      });
    }

    return out;
  }
}

