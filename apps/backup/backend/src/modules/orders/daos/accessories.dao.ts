import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

export type AccessoryRow = {
  id: number;
  name: string;
};

@Injectable()
export class AccessoriesDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS equipment_accessories (
          id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(128) NOT NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          sort_order INT NOT NULL DEFAULT 0,
          category VARCHAR(64) NULL,
          UNIQUE KEY uniq_name (name)
        )
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS order_equipment_accessories (
          order_id INT NOT NULL,
          accessory_id INT NOT NULL,
          is_included TINYINT(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (order_id, accessory_id),
          KEY idx_accessory_id (accessory_id)
        )
        `,
      );
    } catch {
    }
  }

  async list(empresaId: number): Promise<AccessoryRow[]> {
    const conn = await createTenantConnection(this.masterPool, empresaId);
    try {
      await this.ensureSchema(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, name
        FROM equipment_accessories
        WHERE is_active = 1
        ORDER BY sort_order, name
        LIMIT 500
        `,
      );
      return (rows ?? []).map((r) => ({ id: Number(r.id), name: String(r.name ?? '') }));
    } catch {
      return [];
    } finally {
      await conn.end();
    }
  }

  async create(input: { empresaId: number; name: string }): Promise<AccessoryRow | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const name = input.name.trim();
      if (!name) return null;

      try {
        const [exists] = await conn.query<RowDataPacket[]>(
          'SELECT id, name FROM equipment_accessories WHERE name = ? LIMIT 1',
          [name],
        );
        const e = exists?.[0];
        if (e) return { id: Number(e.id), name: String(e.name ?? name) };
      } catch {
      }

      const [result] = await conn.execute(
        `
        INSERT INTO equipment_accessories (name, is_active, sort_order, category)
        VALUES (?, 1, 0, 'general')
        `,
        [name],
      );
      const anyResult = result as { insertId?: number };
      const id = Number(anyResult.insertId ?? 0);
      if (!id) return null;
      return { id, name };
    } catch {
      return null;
    } finally {
      await conn.end();
    }
  }

  async getIncludedIds(input: { empresaId: number; orderId: number }): Promise<number[]> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT accessory_id
        FROM order_equipment_accessories
        WHERE order_id = ? AND is_included = 1
        ORDER BY accessory_id
        `,
        [input.orderId],
      );
      return (rows ?? []).map((r) => Number(r.accessory_id ?? 0)).filter((v) => Number.isFinite(v) && v > 0);
    } catch {
      return [];
    } finally {
      await conn.end();
    }
  }

  async setIncluded(input: { empresaId: number; orderId: number; accessoryIds: number[] }): Promise<void> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const ids = (input.accessoryIds ?? [])
        .map((v) => Number(v))
        .filter((v) => Number.isFinite(v) && v > 0);

      try {
        await conn.execute('DELETE FROM order_equipment_accessories WHERE order_id = ?', [input.orderId]);
      } catch {
      }

      for (const id of ids) {
        try {
          await conn.execute(
            'INSERT INTO order_equipment_accessories (order_id, accessory_id, is_included) VALUES (?, ?, 1)',
            [input.orderId, id],
          );
        } catch {
        }
      }
    } finally {
      await conn.end();
    }
  }
}

