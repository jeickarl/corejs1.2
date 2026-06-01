import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

function asMoney(v: unknown): number {
  const n = Number(v ?? 0);
  if (!Number.isFinite(n)) return 0;
  return Math.round(n * 100) / 100;
}

export type ServiceRow = {
  id: number;
  name: string;
  description: string;
  deviceCategoryId: number;
  deviceCategoryName: string;
  basePrice: number;
  estimatedTime: number;
  notes: string;
  active: boolean;
  createdAt: string;
  updatedAt: string;
};

@Injectable()
export class ServicesDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS services (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          description TEXT NULL,
          device_category_id INT(10) UNSIGNED NOT NULL,
          base_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          estimated_time INT(11) NOT NULL DEFAULT 0,
          notes TEXT NULL,
          active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
          UNIQUE KEY uq_name (name),
          KEY idx_active (active),
          KEY idx_category (device_category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }
  }

  async list(input: {
    empresaId: number;
    search: string;
    categoryId: number | null;
    onlyActive: boolean | null;
    limit: number;
    offset: number;
  }): Promise<{ rows: ServiceRow[]; total: number }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const where: string[] = [];
      const params: Array<string | number> = [];
      const search = input.search.trim();

      if (input.categoryId) {
        where.push('s.device_category_id = ?');
        params.push(input.categoryId);
      }
      if (input.onlyActive === true) {
        where.push('s.active = 1');
      } else if (input.onlyActive === false) {
        where.push('s.active = 0');
      }
      if (search) {
        const sp = `%${search}%`;
        where.push('(s.name LIKE ? OR s.description LIKE ?)');
        params.push(sp, sp);
      }

      const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';
      const [countRows] = await conn.query<RowDataPacket[]>(
        `
        SELECT COUNT(*) as total
        FROM services s
        ${whereSql}
        `,
        params,
      );
      const total = Number(countRows?.[0]?.total ?? 0);

      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT
          s.id, s.name, COALESCE(s.description,'') as description, s.device_category_id,
          COALESCE(c.name,'') as device_category_name,
          s.base_price, s.estimated_time, COALESCE(s.notes,'') as notes, s.active, s.created_at, s.updated_at
        FROM services s
        LEFT JOIN device_categories c ON c.id = s.device_category_id
        ${whereSql}
        ORDER BY s.name ASC
        LIMIT ? OFFSET ?
        `,
        [...params, input.limit, input.offset],
      );
      return {
        total,
        rows: (rows ?? []).map((r) => ({
          id: Number(r.id),
          name: String(r.name ?? ''),
          description: String(r.description ?? ''),
          deviceCategoryId: Number(r.device_category_id ?? 0),
          deviceCategoryName: String(r.device_category_name ?? ''),
          basePrice: asMoney(r.base_price),
          estimatedTime: Number(r.estimated_time ?? 0),
          notes: String(r.notes ?? ''),
          active: Number(r.active ?? 1) === 1,
          createdAt: String(r.created_at ?? ''),
          updatedAt: String(r.updated_at ?? ''),
        })),
      };
    } finally {
      await conn.end();
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<ServiceRow | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT
          s.id, s.name, COALESCE(s.description,'') as description, s.device_category_id,
          COALESCE(c.name,'') as device_category_name,
          s.base_price, s.estimated_time, COALESCE(s.notes,'') as notes, s.active, s.created_at, s.updated_at
        FROM services s
        LEFT JOIN device_categories c ON c.id = s.device_category_id
        WHERE s.id = ?
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
        deviceCategoryId: Number(r.device_category_id ?? 0),
        deviceCategoryName: String(r.device_category_name ?? ''),
        basePrice: asMoney(r.base_price),
        estimatedTime: Number(r.estimated_time ?? 0),
        notes: String(r.notes ?? ''),
        active: Number(r.active ?? 1) === 1,
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
      let sql = 'SELECT id FROM services WHERE name = ?';
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

  async create(input: {
    empresaId: number;
    name: string;
    description: string | null;
    deviceCategoryId: number;
    basePrice: number;
    estimatedTime: number;
    notes: string | null;
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.execute(
        `
        INSERT INTO services (name, description, device_category_id, base_price, estimated_time, notes, active)
        VALUES (?, ?, ?, ?, ?, ?, 1)
        `,
        [input.name, input.description, input.deviceCategoryId, asMoney(input.basePrice), input.estimatedTime, input.notes],
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
    deviceCategoryId: number;
    basePrice: number;
    estimatedTime: number;
    notes: string | null;
    active: boolean;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.execute(
        `
        UPDATE services
        SET name = ?, description = ?, device_category_id = ?, base_price = ?, estimated_time = ?, notes = ?, active = ?, updated_at = NOW()
        WHERE id = ?
        `,
        [
          input.name,
          input.description,
          input.deviceCategoryId,
          asMoney(input.basePrice),
          input.estimatedTime,
          input.notes,
          input.active ? 1 : 0,
          input.id,
        ],
      );
      return Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0) > 0;
    } finally {
      await conn.end();
    }
  }
}

