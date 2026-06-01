import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

export type DeviceCategoryRow = {
  id: number;
  name: string;
  description: string;
  sortOrder: number;
  active: boolean;
  serviceCount: number;
  createdAt: string;
  updatedAt: string;
};

@Injectable()
export class DeviceCategoriesDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS device_categories (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          description TEXT NULL,
          sort_order INT(11) NOT NULL DEFAULT 0,
          active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
          UNIQUE KEY uq_name (name),
          KEY idx_active (active),
          KEY idx_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }
  }

  async list(input: { empresaId: number; onlyActive: boolean | null }): Promise<DeviceCategoryRow[]> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const where: string[] = [];
      const params: Array<string | number> = [];
      if (input.onlyActive === true) {
        where.push('c.active = 1');
      } else if (input.onlyActive === false) {
        where.push('c.active = 0');
      }
      const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';

      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT c.id, c.name, COALESCE(c.description,'') as description, c.sort_order, c.active,
               COUNT(s.id) as service_count, c.created_at, c.updated_at
        FROM device_categories c
        LEFT JOIN services s ON s.device_category_id = c.id
        ${whereSql}
        GROUP BY c.id
        ORDER BY c.sort_order ASC, c.name ASC
        `,
        params,
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        name: String(r.name ?? ''),
        description: String(r.description ?? ''),
        sortOrder: Number(r.sort_order ?? 0),
        active: Number(r.active ?? 1) === 1,
        serviceCount: Number(r.service_count ?? 0),
        createdAt: String(r.created_at ?? ''),
        updatedAt: String(r.updated_at ?? ''),
      }));
    } finally {
      await conn.end();
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<DeviceCategoryRow | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT c.id, c.name, COALESCE(c.description,'') as description, c.sort_order, c.active,
               (SELECT COUNT(*) FROM services s WHERE s.device_category_id = c.id) as service_count,
               c.created_at, c.updated_at
        FROM device_categories c
        WHERE c.id = ?
        LIMIT 1
        `,
        [input.id],
      );
      const r = rows?.[0];
      if (!r) return null;
      return {
        id: Number(r.id),
        name: String(r.name ?? ''),
        description: String(r.description ?? ''),
        sortOrder: Number(r.sort_order ?? 0),
        active: Number(r.active ?? 1) === 1,
        serviceCount: Number(r.service_count ?? 0),
        createdAt: String(r.created_at ?? ''),
        updatedAt: String(r.updated_at ?? ''),
      };
    } finally {
      await conn.end();
    }
  }

  async existsByName(input: { empresaId: number; name: string; idToExclude?: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const params: Array<string | number> = [input.name];
      let sql = 'SELECT id FROM device_categories WHERE name = ?';
      if (input.idToExclude) {
        sql += ' AND id != ?';
        params.push(input.idToExclude);
      }
      sql += ' LIMIT 1';
      const [rows] = await conn.query<RowDataPacket[]>(sql, params);
      return Boolean(rows?.[0]);
    } finally {
      await conn.end();
    }
  }

  async create(input: { empresaId: number; name: string; description: string | null; sortOrder: number }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.execute(
        `
        INSERT INTO device_categories (name, description, sort_order, active)
        VALUES (?, ?, ?, 1)
        `,
        [input.name, input.description, input.sortOrder],
      );
      return Number((r as unknown as { insertId?: number })?.insertId ?? 0);
    } finally {
      await conn.end();
    }
  }

  async update(input: {
    empresaId: number;
    id: number;
    name: string;
    description: string | null;
    sortOrder: number;
    active: boolean;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.execute(
        `
        UPDATE device_categories
        SET name = ?, description = ?, sort_order = ?, active = ?, updated_at = NOW()
        WHERE id = ?
        `,
        [input.name, input.description, input.sortOrder, input.active ? 1 : 0, input.id],
      );
      return Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0) > 0;
    } finally {
      await conn.end();
    }
  }

  async canDelete(input: { empresaId: number; id: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        'SELECT COUNT(*) as c FROM services WHERE device_category_id = ?',
        [input.id],
      );
      return Number(rows?.[0]?.c ?? 0) === 0;
    } finally {
      await conn.end();
    }
  }

  async delete(input: { empresaId: number; id: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.execute('DELETE FROM device_categories WHERE id = ?', [input.id]);
      return Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0) > 0;
    } finally {
      await conn.end();
    }
  }
}

