import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

export type InventoryProductRow = {
  id: number;
  sku: string;
  name: string;
  description: string;
  salePrice: number;
  costPrice: number;
  currentStock: number;
  minStock: number;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
};

export type InventoryMovementRow = {
  id: number;
  productId: number;
  movementType: 'in' | 'out' | 'adjust';
  quantity: number;
  referenceType: string | null;
  referenceId: number | null;
  notes: string | null;
  createdBy: number | null;
  createdAt: string;
};

function asNumber(v: unknown): number {
  const n = Number(v ?? 0);
  if (!Number.isFinite(n)) return 0;
  return Math.round(n * 100) / 100;
}

@Injectable()
export class InventoryDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS inventory_products (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          sku VARCHAR(100) NULL,
          name VARCHAR(255) NOT NULL,
          description TEXT NULL,
          sale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          current_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          min_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
          KEY idx_name (name),
          KEY idx_sku (sku),
          KEY idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS inventory_movements (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          product_id INT(10) UNSIGNED NOT NULL,
          movement_type ENUM('in','out','adjust') NOT NULL,
          quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          reference_type VARCHAR(50) NULL,
          reference_id INT(11) NULL,
          notes TEXT NULL,
          created_by INT(10) UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          KEY idx_product_id (product_id),
          KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query('SELECT sale_price FROM inventory_products LIMIT 1');
    } catch {
      try {
        await conn.query('ALTER TABLE inventory_products ADD COLUMN sale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER description');
      } catch {
      }
    }

    try {
      await conn.query('SELECT current_stock FROM inventory_products LIMIT 1');
    } catch {
      try {
        await conn.query('ALTER TABLE inventory_products ADD COLUMN current_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cost_price');
      } catch {
      }
    }

    try {
      await conn.query('SELECT min_stock FROM inventory_products LIMIT 1');
    } catch {
      try {
        await conn.query('ALTER TABLE inventory_products ADD COLUMN min_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER current_stock');
      } catch {
      }
    }

    try {
      await conn.query('SELECT is_active FROM inventory_products LIMIT 1');
    } catch {
      try {
        await conn.query('ALTER TABLE inventory_products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER min_stock');
      } catch {
      }
    }
  }

  async list(input: {
    empresaId: number;
    search: string;
    limit: number;
    offset: number;
    onlyActive?: boolean;
  }): Promise<{ rows: InventoryProductRow[]; total: number }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const search = input.search.trim();
      const where: string[] = [];
      const params: Array<string | number> = [];
      if (input.onlyActive) {
        where.push('is_active = 1');
      }
      if (search) {
        where.push('(name LIKE ? OR sku LIKE ? OR description LIKE ?)');
        const sp = `%${search}%`;
        params.push(sp, sp, sp);
      }
      const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';
      const [countRows] = await conn.query<RowDataPacket[]>(
        `SELECT COUNT(*) as total FROM inventory_products ${whereSql}`,
        params,
      );
      const total = Number(countRows?.[0]?.total ?? 0);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, COALESCE(sku,'') as sku, name, COALESCE(description,'') as description,
               sale_price, cost_price, current_stock, min_stock, is_active, created_at, updated_at
        FROM inventory_products
        ${whereSql}
        ORDER BY id DESC
        LIMIT ? OFFSET ?
        `,
        [...params, input.limit, input.offset],
      );
      return {
        total,
        rows: (rows ?? []).map((r) => ({
          id: Number(r.id),
          sku: String(r.sku ?? ''),
          name: String(r.name ?? ''),
          description: String(r.description ?? ''),
          salePrice: asNumber(r.sale_price),
          costPrice: asNumber(r.cost_price),
          currentStock: asNumber(r.current_stock),
          minStock: asNumber(r.min_stock),
          isActive: Number(r.is_active ?? 1) === 1,
          createdAt: String(r.created_at ?? ''),
          updatedAt: String(r.updated_at ?? ''),
        })),
      };
    } finally {
      await conn.end();
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<InventoryProductRow | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, COALESCE(sku,'') as sku, name, COALESCE(description,'') as description,
               sale_price, cost_price, current_stock, min_stock, is_active, created_at, updated_at
        FROM inventory_products
        WHERE id = ?
        LIMIT 1
        `,
        [input.id],
      );
      const r = rows?.[0];
      if (!r) return null;
      return {
        id: Number(r.id),
        sku: String(r.sku ?? ''),
        name: String(r.name ?? ''),
        description: String(r.description ?? ''),
        salePrice: asNumber(r.sale_price),
        costPrice: asNumber(r.cost_price),
        currentStock: asNumber(r.current_stock),
        minStock: asNumber(r.min_stock),
        isActive: Number(r.is_active ?? 1) === 1,
        createdAt: String(r.created_at ?? ''),
        updatedAt: String(r.updated_at ?? ''),
      };
    } finally {
      await conn.end();
    }
  }

  async createProduct(input: {
    empresaId: number;
    sku: string | null;
    name: string;
    description: string | null;
    salePrice: number;
    costPrice: number;
    currentStock: number;
    minStock: number;
    isActive: boolean;
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<{ insertId: number } & RowDataPacket[]>(
        `
        INSERT INTO inventory_products (sku, name, description, sale_price, cost_price, current_stock, min_stock, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        `,
        [
          input.sku,
          input.name,
          input.description,
          asNumber(input.salePrice),
          asNumber(input.costPrice),
          asNumber(input.currentStock),
          asNumber(input.minStock),
          input.isActive ? 1 : 0,
        ],
      );
      return Number((r as unknown as { insertId: number })?.insertId ?? 0);
    } finally {
      await conn.end();
    }
  }

  async updateProduct(input: {
    empresaId: number;
    id: number;
    sku: string | null;
    name: string;
    description: string | null;
    salePrice: number;
    costPrice: number;
    minStock: number;
    isActive: boolean;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<RowDataPacket[]>(
        `
        UPDATE inventory_products
        SET sku = ?, name = ?, description = ?, sale_price = ?, cost_price = ?, min_stock = ?, is_active = ?
        WHERE id = ?
        LIMIT 1
        `,
        [
          input.sku,
          input.name,
          input.description,
          asNumber(input.salePrice),
          asNumber(input.costPrice),
          asNumber(input.minStock),
          input.isActive ? 1 : 0,
          input.id,
        ],
      );
      const affected = Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0);
      return affected > 0;
    } finally {
      await conn.end();
    }
  }

  async deactivateProduct(input: { empresaId: number; id: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<RowDataPacket[]>(
        'UPDATE inventory_products SET is_active = 0 WHERE id = ? LIMIT 1',
        [input.id],
      );
      const affected = Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0);
      return affected > 0;
    } finally {
      await conn.end();
    }
  }

  async addMovement(input: {
    empresaId: number;
    productId: number;
    movementType: 'in' | 'out' | 'adjust';
    quantity: number;
    referenceType?: string | null;
    referenceId?: number | null;
    notes?: string | null;
    createdBy?: number | null;
  }): Promise<{ ok: true; movementId: number; newStock: number } | { ok: false; code: 'NOT_FOUND' | 'INSUFFICIENT_STOCK' }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const qty = asNumber(input.quantity);
      if (qty <= 0) return { ok: false, code: 'NOT_FOUND' };

      await conn.beginTransaction();
      const [rows] = await conn.query<RowDataPacket[]>(
        'SELECT id, current_stock FROM inventory_products WHERE id = ? LIMIT 1 FOR UPDATE',
        [input.productId],
      );
      const r = rows?.[0];
      if (!r) {
        await conn.rollback();
        return { ok: false, code: 'NOT_FOUND' };
      }
      const current = asNumber(r.current_stock);
      let next = current;
      if (input.movementType === 'in') next = asNumber(current + qty);
      if (input.movementType === 'out') {
        next = asNumber(current - qty);
        if (next < 0) {
          await conn.rollback();
          return { ok: false, code: 'INSUFFICIENT_STOCK' };
        }
      }
      if (input.movementType === 'adjust') next = qty;

      const [ins] = await conn.query<{ insertId: number } & RowDataPacket[]>(
        `
        INSERT INTO inventory_movements (product_id, movement_type, quantity, reference_type, reference_id, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        `,
        [
          input.productId,
          input.movementType,
          qty,
          input.referenceType ?? null,
          input.referenceId ?? null,
          input.notes ?? null,
          input.createdBy ?? null,
        ],
      );
      await conn.query('UPDATE inventory_products SET current_stock = ? WHERE id = ? LIMIT 1', [next, input.productId]);
      await conn.commit();
      return { ok: true, movementId: Number((ins as unknown as { insertId: number })?.insertId ?? 0), newStock: next };
    } catch {
      try {
        await conn.rollback();
      } catch {
      }
      return { ok: false, code: 'NOT_FOUND' };
    } finally {
      await conn.end();
    }
  }

  async listMovements(input: {
    empresaId: number;
    productId: number;
    limit: number;
    offset: number;
  }): Promise<{ rows: InventoryMovementRow[]; total: number }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [countRows] = await conn.query<RowDataPacket[]>(
        'SELECT COUNT(*) as total FROM inventory_movements WHERE product_id = ?',
        [input.productId],
      );
      const total = Number(countRows?.[0]?.total ?? 0);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, product_id, movement_type, quantity, reference_type, reference_id, notes, created_by, created_at
        FROM inventory_movements
        WHERE product_id = ?
        ORDER BY id DESC
        LIMIT ? OFFSET ?
        `,
        [input.productId, input.limit, input.offset],
      );
      return {
        total,
        rows: (rows ?? []).map((r) => ({
          id: Number(r.id),
          productId: Number(r.product_id),
          movementType: String(r.movement_type ?? 'adjust') as InventoryMovementRow['movementType'],
          quantity: asNumber(r.quantity),
          referenceType: r.reference_type === undefined ? null : (r.reference_type as string | null),
          referenceId: r.reference_id === undefined || r.reference_id === null ? null : Number(r.reference_id),
          notes: r.notes === undefined ? null : (r.notes as string | null),
          createdBy: r.created_by === undefined || r.created_by === null ? null : Number(r.created_by),
          createdAt: String(r.created_at ?? ''),
        })),
      };
    } finally {
      await conn.end();
    }
  }
}

