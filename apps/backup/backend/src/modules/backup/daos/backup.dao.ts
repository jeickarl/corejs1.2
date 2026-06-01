import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

export type BackupPayload = {
  kind: 'corejs-backup';
  version: 1;
  createdAt: string;
  tables: Record<string, Array<Record<string, unknown>>>;
};

@Injectable()
export class BackupDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  async exportTenant(input: { empresaId: number }): Promise<BackupPayload> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      const [tablesRows] = await conn.query<RowDataPacket[]>('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
      const tableNames = (tablesRows ?? [])
        .map((r) => String(Object.values(r ?? {})[0] ?? ''))
        .filter((t) => t && t !== 'saas_migrations')
        .sort((a, b) => a.localeCompare(b));

      const tables: Record<string, Array<Record<string, unknown>>> = {};
      for (const t of tableNames) {
        try {
          const [rows] = await conn.query<RowDataPacket[]>(`SELECT * FROM \`${t}\``);
          tables[t] = (rows ?? []).map((x) => ({ ...(x as unknown as Record<string, unknown>) }));
        } catch {
          tables[t] = [];
        }
      }

      return {
        kind: 'corejs-backup',
        version: 1,
        createdAt: new Date().toISOString(),
        tables,
      };
    } finally {
      await conn.end();
    }
  }

  async importTenant(input: { empresaId: number; backup: BackupPayload; mode: 'replace' | 'append' }): Promise<void> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await conn.beginTransaction();
      try {
        await conn.query('SET FOREIGN_KEY_CHECKS=0');

        const tableNames = Object.keys(input.backup.tables ?? {}).sort((a, b) => a.localeCompare(b));
        if (input.mode === 'replace') {
          for (const t of tableNames) {
            try {
              await conn.query(`TRUNCATE TABLE \`${t}\``);
            } catch {
              try {
                await conn.query(`DELETE FROM \`${t}\``);
              } catch {
              }
            }
          }
        }

        for (const t of tableNames) {
          const rows = input.backup.tables?.[t] ?? [];
          if (!Array.isArray(rows) || rows.length === 0) continue;

          const columns = Object.keys(rows[0] ?? {});
          if (columns.length === 0) continue;

          const colSql = columns.map((c) => `\`${c}\``).join(', ');
          const batchSize = 500;

          for (let i = 0; i < rows.length; i += batchSize) {
            const batch = rows.slice(i, i + batchSize);
            const values = batch.map((r) => columns.map((c) => (r as Record<string, unknown>)[c]));
            await conn.query(`INSERT INTO \`${t}\` (${colSql}) VALUES ?`, [values]);
          }
        }

        await conn.query('SET FOREIGN_KEY_CHECKS=1');
        await conn.commit();
      } catch (e) {
        try {
          await conn.query('SET FOREIGN_KEY_CHECKS=1');
        } catch {
        }
        try {
          await conn.rollback();
        } catch {
        }
        throw e;
      }
    } finally {
      await conn.end();
    }
  }
}

