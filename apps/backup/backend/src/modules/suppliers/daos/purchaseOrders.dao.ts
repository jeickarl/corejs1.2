import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

export type PurchaseOrderRow = {
  id: number;
  poNumber: string;
  supplierId: number;
  supplierName: string;
  orderDate: string;
  expectedDate: string | null;
  paymentMethod: string;
  paymentTerms: string;
  notes: string;
  totalAmount: number;
  paymentStatus: string;
  status: string;
  createdAt: string;
  updatedAt: string;
};

type PurchaseOrderSchema = 'v2' | 'v1';

@Injectable()
export class PurchaseOrdersDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS purchase_orders (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          po_number VARCHAR(50) NOT NULL,
          supplier_id INT(10) UNSIGNED NOT NULL,
          order_date DATE NOT NULL,
          expected_date DATE NULL,
          payment_method VARCHAR(100) NULL,
          payment_terms VARCHAR(100) NULL,
          notes TEXT NULL,
          total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          payment_status ENUM('pending','partially_paid','paid') NOT NULL DEFAULT 'pending',
          status ENUM('draft','sent','received','cancelled') NOT NULL DEFAULT 'draft',
          created_by INT(10) UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
          KEY idx_po_number (po_number),
          KEY idx_supplier_id (supplier_id),
          KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }
  }

  private async detectSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>): Promise<PurchaseOrderSchema> {
    try {
      await conn.query('SELECT po_number, payment_status, status FROM purchase_orders LIMIT 1');
      return 'v2';
    } catch {
      return 'v1';
    }
  }

  private mapRowV2(r: RowDataPacket): PurchaseOrderRow {
    return {
      id: Number(r.id),
      poNumber: String(r.po_number ?? ''),
      supplierId: Number(r.supplier_id ?? 0),
      supplierName: String(r.supplier_name ?? ''),
      orderDate: String(r.order_date ?? ''),
      expectedDate: r.expected_date === undefined || r.expected_date === null ? null : String(r.expected_date),
      paymentMethod: String(r.payment_method ?? ''),
      paymentTerms: String(r.payment_terms ?? ''),
      notes: String(r.notes ?? ''),
      totalAmount: Number(r.total_amount ?? 0),
      paymentStatus: String(r.payment_status ?? 'pending'),
      status: String(r.status ?? 'draft'),
      createdAt: String(r.created_at ?? ''),
      updatedAt: String(r.updated_at ?? ''),
    };
  }

  private mapRowV1(r: RowDataPacket): PurchaseOrderRow {
    return {
      id: Number(r.id),
      poNumber: String(r.po_number ?? r.order_number ?? ''),
      supplierId: Number(r.supplier_id ?? 0),
      supplierName: String(r.supplier_name ?? ''),
      orderDate: String(r.order_date ?? ''),
      expectedDate: r.expected_date === undefined || r.expected_date === null ? null : String(r.expected_date),
      paymentMethod: String(r.payment_method ?? ''),
      paymentTerms: String(r.payment_terms ?? ''),
      notes: String(r.notes ?? ''),
      totalAmount: Number(r.total_amount ?? r.grand_total ?? 0),
      paymentStatus: String(r.payment_status ?? 'pending'),
      status: String(r.status ?? 'draft'),
      createdAt: String(r.created_at ?? ''),
      updatedAt: String(r.updated_at ?? ''),
    };
  }

  private makePoNumber(): string {
    const d = new Date();
    const y = String(d.getFullYear());
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const rand = Math.random().toString(16).slice(2, 10).toUpperCase();
    return `PO-${y}${m}${day}-${rand}`;
  }

  async list(input: {
    empresaId: number;
    search: string;
    supplierId: number | null;
    status: string | null;
    limit: number;
    offset: number;
  }): Promise<{ rows: PurchaseOrderRow[]; total: number }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const schema = await this.detectSchema(conn);
      const where: string[] = [];
      const params: Array<string | number> = [];
      const search = input.search.trim();

      if (input.supplierId) {
        where.push('po.supplier_id = ?');
        params.push(input.supplierId);
      }
      if (input.status) {
        where.push('po.status = ?');
        params.push(input.status);
      }
      if (search) {
        const sp = `%${search}%`;
        where.push('(po.po_number LIKE ? OR s.company_name LIKE ? OR s.name LIKE ?)');
        params.push(sp, sp, sp);
      }

      const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';
      const [countRows] = await conn.query<RowDataPacket[]>(
        `
        SELECT COUNT(*) as total
        FROM purchase_orders po
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        ${whereSql}
        `,
        params,
      );
      const total = Number(countRows?.[0]?.total ?? 0);

      const selectSql =
        schema === 'v2'
          ? `
          SELECT
            po.id, po.po_number, po.supplier_id, po.order_date, po.expected_date,
            po.payment_method, po.payment_terms, po.notes, po.total_amount, po.payment_status, po.status,
            po.created_at, po.updated_at,
            COALESCE(s.company_name, s.name, '') as supplier_name
          FROM purchase_orders po
          LEFT JOIN suppliers s ON s.id = po.supplier_id
          ${whereSql}
          ORDER BY po.created_at DESC
          LIMIT ? OFFSET ?
          `
          : `
          SELECT
            po.*,
            COALESCE(s.company_name, s.name, '') as supplier_name
          FROM purchase_orders po
          LEFT JOIN suppliers s ON s.id = po.supplier_id
          ${whereSql}
          ORDER BY po.created_at DESC
          LIMIT ? OFFSET ?
          `;

      const [rows] = await conn.query<RowDataPacket[]>(selectSql, [...params, input.limit, input.offset]);
      return {
        total,
        rows: (rows ?? []).map((r) => (schema === 'v2' ? this.mapRowV2(r) : this.mapRowV1(r))),
      };
    } finally {
      await conn.end();
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<PurchaseOrderRow | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const schema = await this.detectSchema(conn);
      const selectSql =
        schema === 'v2'
          ? `
          SELECT
            po.id, po.po_number, po.supplier_id, po.order_date, po.expected_date,
            po.payment_method, po.payment_terms, po.notes, po.total_amount, po.payment_status, po.status,
            po.created_at, po.updated_at,
            COALESCE(s.company_name, s.name, '') as supplier_name
          FROM purchase_orders po
          LEFT JOIN suppliers s ON s.id = po.supplier_id
          WHERE po.id = ?
          LIMIT 1
          `
          : `
          SELECT
            po.*,
            COALESCE(s.company_name, s.name, '') as supplier_name
          FROM purchase_orders po
          LEFT JOIN suppliers s ON s.id = po.supplier_id
          WHERE po.id = ?
          LIMIT 1
          `;
      const [rows] = await conn.query<RowDataPacket[]>(selectSql, [input.id]);
      const r = rows?.[0];
      if (!r) return null;
      return schema === 'v2' ? this.mapRowV2(r) : this.mapRowV1(r);
    } finally {
      await conn.end();
    }
  }

  async create(input: {
    empresaId: number;
    supplierId: number;
    orderDate: string;
    expectedDate: string | null;
    paymentMethod: string | null;
    paymentTerms: string | null;
    notes: string | null;
    createdByUserId: number | null;
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const schema = await this.detectSchema(conn);
      const poNumber = this.makePoNumber();

      if (schema === 'v2') {
        const [result] = await conn.execute(
          `
          INSERT INTO purchase_orders (
            po_number, supplier_id, order_date, expected_date, payment_method, payment_terms, notes,
            total_amount, payment_status, status, created_by, created_at, updated_at
          )
          VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'pending', 'draft', ?, NOW(), NOW())
          `,
          [
            poNumber,
            input.supplierId,
            input.orderDate,
            input.expectedDate,
            input.paymentMethod,
            input.paymentTerms,
            input.notes,
            input.createdByUserId,
          ],
        );
        const anyRes = result as unknown as { insertId?: number };
        return Number(anyRes.insertId ?? 0);
      }

      const [result] = await conn.execute(
        `
        INSERT INTO purchase_orders (po_number, supplier_id, order_date, expected_date, payment_method, payment_terms, notes, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        `,
        [
          poNumber,
          input.supplierId,
          input.orderDate,
          input.expectedDate,
          input.paymentMethod,
          input.paymentTerms,
          input.notes,
          input.createdByUserId,
        ],
      );
      const anyRes = result as unknown as { insertId?: number };
      return Number(anyRes.insertId ?? 0);
    } finally {
      await conn.end();
    }
  }

  async update(input: {
    empresaId: number;
    id: number;
    supplierId: number;
    orderDate: string;
    expectedDate: string | null;
    paymentMethod: string | null;
    paymentTerms: string | null;
    notes: string | null;
    status: string | null;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const schema = await this.detectSchema(conn);
      if (schema === 'v2') {
        await conn.execute(
          `
          UPDATE purchase_orders
          SET supplier_id = ?, order_date = ?, expected_date = ?, payment_method = ?, payment_terms = ?, notes = ?,
              status = COALESCE(?, status), updated_at = NOW()
          WHERE id = ?
          `,
          [
            input.supplierId,
            input.orderDate,
            input.expectedDate,
            input.paymentMethod,
            input.paymentTerms,
            input.notes,
            input.status,
            input.id,
          ],
        );
        return true;
      }

      await conn.execute(
        `
        UPDATE purchase_orders
        SET supplier_id = ?, order_date = ?, expected_date = ?, payment_method = ?, payment_terms = ?, notes = ?, updated_at = NOW()
        WHERE id = ?
        `,
        [
          input.supplierId,
          input.orderDate,
          input.expectedDate,
          input.paymentMethod,
          input.paymentTerms,
          input.notes,
          input.id,
        ],
      );
      return true;
    } finally {
      await conn.end();
    }
  }

  async cancel(input: { empresaId: number; id: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const schema = await this.detectSchema(conn);
      if (schema === 'v2') {
        await conn.execute("UPDATE purchase_orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?", [input.id]);
        return true;
      }
      await conn.execute('DELETE FROM purchase_orders WHERE id = ?', [input.id]);
      return true;
    } finally {
      await conn.end();
    }
  }
}
