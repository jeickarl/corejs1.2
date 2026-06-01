import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

function asMoney(v: unknown): number {
  const n = Number(v ?? 0);
  if (!Number.isFinite(n)) return 0;
  return Math.round(n * 100) / 100;
}

export type PurchaseReceiptItemRow = {
  id: number;
  receiptId: number;
  productId: number;
  productName: string;
  quantity: number;
  unitCost: number;
  subtotal: number;
};

export type PurchaseReceiptRow = {
  id: number;
  receiptNumber: string;
  purchaseOrderId: number;
  poNumber: string;
  supplierId: number;
  supplierName: string;
  receivedDate: string;
  notes: string;
  totalAmount: number;
  createdAt: string;
  createdBy: number | null;
  items: PurchaseReceiptItemRow[];
};

@Injectable()
export class PurchaseReceiptsDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS purchase_receipts (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          receipt_number VARCHAR(50) NOT NULL,
          purchase_order_id INT(10) UNSIGNED NOT NULL,
          supplier_id INT(10) UNSIGNED NOT NULL,
          received_date DATE NOT NULL,
          notes TEXT NULL,
          total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          created_by INT(10) UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          KEY idx_receipt_number (receipt_number),
          KEY idx_po_id (purchase_order_id),
          KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS purchase_receipt_items (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          receipt_id INT(10) UNSIGNED NOT NULL,
          product_id INT(10) UNSIGNED NOT NULL,
          quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          KEY idx_receipt_id (receipt_id),
          KEY idx_product_id (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }
  }

  private async ensureInventorySchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
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
  }

  private makeReceiptNumber(): string {
    const d = new Date();
    const y = String(d.getFullYear());
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const rand = Math.random().toString(16).slice(2, 10).toUpperCase();
    return `RCV-${y}${m}${day}-${rand}`;
  }

  private async loadItems(conn: Awaited<ReturnType<typeof createTenantConnection>>, receiptId: number): Promise<PurchaseReceiptItemRow[]> {
    const [rows] = await conn.query<RowDataPacket[]>(
      `
      SELECT it.id, it.receipt_id, it.product_id, it.quantity, it.unit_cost, it.subtotal,
             COALESCE(p.name,'') as product_name
      FROM purchase_receipt_items it
      LEFT JOIN inventory_products p ON p.id = it.product_id
      WHERE it.receipt_id = ?
      ORDER BY it.id ASC
      `,
      [receiptId],
    );
    return (rows ?? []).map((r) => ({
      id: Number(r.id),
      receiptId: Number(r.receipt_id ?? 0),
      productId: Number(r.product_id ?? 0),
      productName: String(r.product_name ?? ''),
      quantity: asMoney(r.quantity),
      unitCost: asMoney(r.unit_cost),
      subtotal: asMoney(r.subtotal),
    }));
  }

  async list(input: { empresaId: number; search: string; limit: number; offset: number }): Promise<{ rows: PurchaseReceiptRow[]; total: number }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      await this.ensureInventorySchema(conn);
      const search = input.search.trim();
      const where: string[] = [];
      const params: Array<string | number> = [];
      if (search) {
        const sp = `%${search}%`;
        where.push('(r.receipt_number LIKE ? OR po.po_number LIKE ? OR s.company_name LIKE ? OR s.name LIKE ?)');
        params.push(sp, sp, sp, sp);
      }
      const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';
      const [countRows] = await conn.query<RowDataPacket[]>(
        `
        SELECT COUNT(*) as total
        FROM purchase_receipts r
        LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN suppliers s ON s.id = r.supplier_id
        ${whereSql}
        `,
        params,
      );
      const total = Number(countRows?.[0]?.total ?? 0);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT
          r.*,
          COALESCE(po.po_number,'') as po_number,
          COALESCE(s.company_name, s.name, '') as supplier_name
        FROM purchase_receipts r
        LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN suppliers s ON s.id = r.supplier_id
        ${whereSql}
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?
        `,
        [...params, input.limit, input.offset],
      );
      const receipts: PurchaseReceiptRow[] = [];
      for (const r of rows ?? []) {
        receipts.push({
          id: Number(r.id),
          receiptNumber: String(r.receipt_number ?? ''),
          purchaseOrderId: Number(r.purchase_order_id ?? 0),
          poNumber: String(r.po_number ?? ''),
          supplierId: Number(r.supplier_id ?? 0),
          supplierName: String(r.supplier_name ?? ''),
          receivedDate: String(r.received_date ?? ''),
          notes: String(r.notes ?? ''),
          totalAmount: asMoney(r.total_amount),
          createdAt: String(r.created_at ?? ''),
          createdBy: r.created_by === undefined || r.created_by === null ? null : Number(r.created_by),
          items: [],
        });
      }
      return { total, rows: receipts };
    } finally {
      await conn.end();
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<PurchaseReceiptRow | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      await this.ensureInventorySchema(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT
          r.*,
          COALESCE(po.po_number,'') as po_number,
          COALESCE(s.company_name, s.name, '') as supplier_name
        FROM purchase_receipts r
        LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN suppliers s ON s.id = r.supplier_id
        WHERE r.id = ?
        LIMIT 1
        `,
        [input.id],
      );
      const r = rows?.[0];
      if (!r) return null;
      const items = await this.loadItems(conn, Number(r.id));
      return {
        id: Number(r.id),
        receiptNumber: String(r.receipt_number ?? ''),
        purchaseOrderId: Number(r.purchase_order_id ?? 0),
        poNumber: String(r.po_number ?? ''),
        supplierId: Number(r.supplier_id ?? 0),
        supplierName: String(r.supplier_name ?? ''),
        receivedDate: String(r.received_date ?? ''),
        notes: String(r.notes ?? ''),
        totalAmount: asMoney(r.total_amount),
        createdAt: String(r.created_at ?? ''),
        createdBy: r.created_by === undefined || r.created_by === null ? null : Number(r.created_by),
        items,
      };
    } finally {
      await conn.end();
    }
  }

  async createReceipt(input: {
    empresaId: number;
    purchaseOrderId: number;
    supplierId: number;
    receivedDate: string;
    notes: string | null;
    createdBy: number | null;
    items: Array<{ productId: number; quantity: number; unitCost: number }>;
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      await this.ensureInventorySchema(conn);

      const receiptNumber = this.makeReceiptNumber();
      const totalAmount = asMoney(
        input.items.reduce((acc, it) => acc + asMoney(it.quantity) * asMoney(it.unitCost), 0),
      );

      await conn.beginTransaction();
      try {
        for (const it of input.items) {
          await conn.query<RowDataPacket[]>('SELECT id FROM inventory_products WHERE id = ? LIMIT 1 FOR UPDATE', [
            it.productId,
          ]);
        }

        const [r] = await conn.execute(
          `
          INSERT INTO purchase_receipts (receipt_number, purchase_order_id, supplier_id, received_date, notes, total_amount, created_by, created_at)
          VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
          `,
          [
            receiptNumber,
            input.purchaseOrderId,
            input.supplierId,
            input.receivedDate,
            input.notes,
            totalAmount,
            input.createdBy,
          ],
        );
        const receiptId = Number((r as unknown as { insertId?: number })?.insertId ?? 0);

        for (const it of input.items) {
          const qty = asMoney(it.quantity);
          const unit = asMoney(it.unitCost);
          const sub = asMoney(qty * unit);
          await conn.execute(
            `
            INSERT INTO purchase_receipt_items (receipt_id, product_id, quantity, unit_cost, subtotal)
            VALUES (?, ?, ?, ?, ?)
            `,
            [receiptId, it.productId, qty, unit, sub],
          );
          await conn.execute('UPDATE inventory_products SET current_stock = current_stock + ? WHERE id = ? LIMIT 1', [
            qty,
            it.productId,
          ]);
          await conn.execute(
            `
            INSERT INTO inventory_movements (product_id, movement_type, quantity, reference_type, reference_id, notes, created_by, created_at)
            VALUES (?, 'in', ?, 'purchase_receipt', ?, ?, ?, NOW())
            `,
            [it.productId, qty, receiptId, `Recepción ${receiptNumber}`, input.createdBy],
          );
        }

        try {
          await conn.execute(
            `
            UPDATE purchase_orders
            SET total_amount = COALESCE(?, total_amount),
                status = 'received',
                updated_at = NOW()
            WHERE id = ?
            `,
            [totalAmount, input.purchaseOrderId],
          );
        } catch {
        }

        await conn.commit();
        return receiptId;
      } catch (e) {
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

