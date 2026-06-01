import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

function asMoney(v: unknown): number {
  const n = Number(v ?? 0);
  if (!Number.isFinite(n)) return 0;
  return Math.round(n * 100) / 100;
}

export type SupplierPaymentRow = {
  id: number;
  supplierId: number;
  supplierName: string;
  purchaseOrderId: number | null;
  poNumber: string | null;
  paymentAmount: number;
  paymentMethod: string | null;
  paymentDate: string;
  referenceNumber: string | null;
  notes: string | null;
  cashSessionId: number | null;
  status: string;
  createdAt: string;
  createdBy: number | null;
};

export type PendingPurchaseOrderRow = {
  id: number;
  poNumber: string;
  supplierId: number;
  supplierName: string;
  orderDate: string;
  status: string;
  paymentStatus: string;
  totalAmount: number;
  paidAmount: number;
  pendingAmount: number;
};

type PaymentsSchema = {
  hasStatus: boolean;
  hasRequestId: boolean;
  hasPoPaymentStatus: boolean;
  hasPoTotalAmount: boolean;
  hasCashIncomeTable: boolean;
};

@Injectable()
export class SupplierPaymentsDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS supplier_payments (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          supplier_id INT(10) UNSIGNED NOT NULL,
          purchase_order_id INT(10) UNSIGNED NULL,
          payment_amount DECIMAL(12,2) NOT NULL,
          payment_method VARCHAR(100) NULL,
          payment_date DATE NOT NULL,
          reference_number VARCHAR(100) NULL,
          notes TEXT NULL,
          cash_session_id INT(10) UNSIGNED NULL,
          created_by INT(10) UNSIGNED NULL,
          request_id VARCHAR(64) NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'active',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          KEY idx_supplier_id (supplier_id),
          KEY idx_purchase_order_id (purchase_order_id),
          KEY idx_cash_session_id (cash_session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }
  }

  private async detectSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>): Promise<PaymentsSchema> {
    let hasStatus = false;
    let hasRequestId = false;
    let hasPoPaymentStatus = false;
    let hasPoTotalAmount = false;
    let hasCashIncomeTable = false;

    try {
      await conn.query('SELECT status FROM supplier_payments LIMIT 1');
      hasStatus = true;
    } catch {
    }
    try {
      await conn.query('SELECT request_id FROM supplier_payments LIMIT 1');
      hasRequestId = true;
    } catch {
    }
    try {
      await conn.query('SELECT payment_status FROM purchase_orders LIMIT 1');
      hasPoPaymentStatus = true;
    } catch {
    }
    try {
      await conn.query('SELECT total_amount FROM purchase_orders LIMIT 1');
      hasPoTotalAmount = true;
    } catch {
    }
    try {
      await conn.query('SELECT id FROM cash_income LIMIT 1');
      hasCashIncomeTable = true;
    } catch {
    }

    return { hasStatus, hasRequestId, hasPoPaymentStatus, hasPoTotalAmount, hasCashIncomeTable };
  }

  private async getOpenCashSessionId(conn: Awaited<ReturnType<typeof createTenantConnection>>): Promise<number | null> {
    try {
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id
        FROM cash_sessions
        WHERE status = 'open'
        ORDER BY opening_date DESC
        LIMIT 1
        `,
      );
      const id = rows?.[0]?.id;
      if (id === undefined || id === null) return null;
      const n = Number(id);
      if (!Number.isFinite(n) || n <= 0) return null;
      return n;
    } catch {
      return null;
    }
  }

  private async insertCashExpense(conn: Awaited<ReturnType<typeof createTenantConnection>>, input: {
    cashSessionId: number;
    amount: number;
    concept: string;
    notes: string | null;
    referenceNumber: string | null;
    createdBy: number | null;
  }) {
    try {
      await conn.execute(
        `
        INSERT INTO cash_expenses (cash_session_id, concept, amount, notes, reference_number, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        `,
        [
          input.cashSessionId,
          input.concept,
          asMoney(input.amount),
          input.notes,
          input.referenceNumber,
          input.createdBy,
        ],
      );
    } catch {
    }
  }

  private async insertCashIncome(conn: Awaited<ReturnType<typeof createTenantConnection>>, input: {
    cashSessionId: number;
    amount: number;
    concept: string;
    notes: string | null;
    referenceNumber: string | null;
    createdBy: number | null;
  }) {
    try {
      await conn.execute(
        `
        INSERT INTO cash_income (cash_session_id, income_type, concept, amount, payment_method, reference_number, notes, created_by, created_at)
        VALUES (?, 'supplier_payment_void', ?, ?, NULL, ?, ?, ?, NOW())
        `,
        [
          input.cashSessionId,
          input.concept,
          asMoney(input.amount),
          input.referenceNumber,
          input.notes,
          input.createdBy,
        ],
      );
    } catch {
    }
  }

  private mapPaymentRow(r: RowDataPacket): SupplierPaymentRow {
    return {
      id: Number(r.id),
      supplierId: Number(r.supplier_id ?? 0),
      supplierName: String(r.supplier_name ?? ''),
      purchaseOrderId: r.purchase_order_id === undefined || r.purchase_order_id === null ? null : Number(r.purchase_order_id),
      poNumber: r.po_number === undefined || r.po_number === null ? null : String(r.po_number),
      paymentAmount: asMoney(r.payment_amount),
      paymentMethod: r.payment_method === undefined || r.payment_method === null ? null : String(r.payment_method),
      paymentDate: String(r.payment_date ?? ''),
      referenceNumber: r.reference_number === undefined || r.reference_number === null ? null : String(r.reference_number),
      notes: r.notes === undefined || r.notes === null ? null : String(r.notes),
      cashSessionId: r.cash_session_id === undefined || r.cash_session_id === null ? null : Number(r.cash_session_id),
      status: String(r.status ?? 'active'),
      createdAt: String(r.created_at ?? ''),
      createdBy: r.created_by === undefined || r.created_by === null ? null : Number(r.created_by),
    };
  }

  async listRecent(input: {
    empresaId: number;
    limit: number;
    offset: number;
    includeVoided: boolean;
  }): Promise<{ rows: SupplierPaymentRow[]; total: number }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const s = await this.detectSchema(conn);
      const limit = Math.min(Math.max(1, input.limit), 200);
      const offset = Math.max(0, input.offset);

      const where: string[] = [];
      if (!input.includeVoided && s.hasStatus) {
        where.push("p.status != 'voided'");
      }
      const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';

      const [countRows] = await conn.query<RowDataPacket[]>(
        `
        SELECT COUNT(*) as total
        FROM supplier_payments p
        ${whereSql}
        `,
      );
      const total = Number(countRows?.[0]?.total ?? 0);

      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT
          p.*,
          COALESCE(sup.company_name, sup.name, '') as supplier_name,
          po.po_number as po_number
        FROM supplier_payments p
        LEFT JOIN suppliers sup ON sup.id = p.supplier_id
        LEFT JOIN purchase_orders po ON po.id = p.purchase_order_id
        ${whereSql}
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?
        `,
        [limit, offset],
      );
      return { total, rows: (rows ?? []).map((r) => this.mapPaymentRow(r)) };
    } finally {
      await conn.end();
    }
  }

  async listVoided(input: { empresaId: number; limit: number; offset: number }): Promise<{ rows: SupplierPaymentRow[]; total: number }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const s = await this.detectSchema(conn);
      if (!s.hasStatus) return { total: 0, rows: [] };

      const limit = Math.min(Math.max(1, input.limit), 200);
      const offset = Math.max(0, input.offset);
      const [countRows] = await conn.query<RowDataPacket[]>(
        `
        SELECT COUNT(*) as total
        FROM supplier_payments
        WHERE status = 'voided'
        `,
      );
      const total = Number(countRows?.[0]?.total ?? 0);

      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT
          p.*,
          COALESCE(sup.company_name, sup.name, '') as supplier_name,
          po.po_number as po_number
        FROM supplier_payments p
        LEFT JOIN suppliers sup ON sup.id = p.supplier_id
        LEFT JOIN purchase_orders po ON po.id = p.purchase_order_id
        WHERE p.status = 'voided'
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?
        `,
        [limit, offset],
      );
      return { total, rows: (rows ?? []).map((r) => this.mapPaymentRow(r)) };
    } finally {
      await conn.end();
    }
  }

  async pendingOrders(input: {
    empresaId: number;
    limit: number;
    offset: number;
  }): Promise<{ rows: PendingPurchaseOrderRow[]; total: number }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const s = await this.detectSchema(conn);
      const limit = Math.min(Math.max(1, input.limit), 200);
      const offset = Math.max(0, input.offset);

      const where: string[] = [];
      if (s.hasPoPaymentStatus) {
        where.push("(po.payment_status = 'pending' OR po.payment_status = 'partially_paid')");
      }
      where.push("po.status != 'cancelled'");
      const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';

      const [countRows] = await conn.query<RowDataPacket[]>(
        `
        SELECT COUNT(*) as total
        FROM purchase_orders po
        ${whereSql}
        `,
      );
      const total = Number(countRows?.[0]?.total ?? 0);

      const sumPaymentsExpr = s.hasStatus
        ? "COALESCE(SUM(CASE WHEN sp.status != 'voided' THEN sp.payment_amount ELSE 0 END), 0)"
        : 'COALESCE(SUM(sp.payment_amount), 0)';

      const totalAmountExpr = s.hasPoTotalAmount ? 'po.total_amount' : '0';

      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT
          po.id,
          po.po_number,
          po.supplier_id,
          po.order_date,
          po.status,
          ${s.hasPoPaymentStatus ? 'po.payment_status' : "'pending' as payment_status"},
          ${totalAmountExpr} as total_amount,
          ${sumPaymentsExpr} as paid_amount,
          COALESCE(${totalAmountExpr} - ${sumPaymentsExpr}, 0) as pending_amount,
          COALESCE(sup.company_name, sup.name, '') as supplier_name
        FROM purchase_orders po
        LEFT JOIN suppliers sup ON sup.id = po.supplier_id
        LEFT JOIN supplier_payments sp ON sp.purchase_order_id = po.id
        ${whereSql}
        GROUP BY po.id
        ORDER BY po.created_at DESC
        LIMIT ? OFFSET ?
        `,
        [limit, offset],
      );

      return {
        total,
        rows: (rows ?? []).map((r) => ({
          id: Number(r.id),
          poNumber: String(r.po_number ?? ''),
          supplierId: Number(r.supplier_id ?? 0),
          supplierName: String(r.supplier_name ?? ''),
          orderDate: String(r.order_date ?? ''),
          status: String(r.status ?? 'draft'),
          paymentStatus: String(r.payment_status ?? 'pending'),
          totalAmount: asMoney(r.total_amount),
          paidAmount: asMoney(r.paid_amount),
          pendingAmount: asMoney(r.pending_amount),
        })),
      };
    } finally {
      await conn.end();
    }
  }

  private async recalcPurchaseOrderPaymentStatus(conn: Awaited<ReturnType<typeof createTenantConnection>>, schema: PaymentsSchema, purchaseOrderId: number) {
    if (!schema.hasPoPaymentStatus || !schema.hasPoTotalAmount) return;
    const sumExpr = schema.hasStatus
      ? "COALESCE(SUM(CASE WHEN status != 'voided' THEN payment_amount ELSE 0 END), 0)"
      : 'COALESCE(SUM(payment_amount), 0)';
    const [rows] = await conn.query<RowDataPacket[]>(
      `
      SELECT ${sumExpr} as paid
      FROM supplier_payments
      WHERE purchase_order_id = ?
      `,
      [purchaseOrderId],
    );
    const paid = asMoney(rows?.[0]?.paid ?? 0);
    const [poRows] = await conn.query<RowDataPacket[]>(
      'SELECT total_amount FROM purchase_orders WHERE id = ? LIMIT 1',
      [purchaseOrderId],
    );
    const totalAmount = asMoney(poRows?.[0]?.total_amount ?? 0);
    const next =
      totalAmount <= 0
        ? paid > 0
          ? 'paid'
          : 'pending'
        : paid <= 0
          ? 'pending'
          : paid + 0.00001 >= totalAmount
            ? 'paid'
            : 'partially_paid';
    await conn.execute('UPDATE purchase_orders SET payment_status = ?, updated_at = NOW() WHERE id = ?', [
      next,
      purchaseOrderId,
    ]);
  }

  async createPayment(input: {
    empresaId: number;
    supplierId: number;
    purchaseOrderId: number | null;
    paymentAmount: number;
    paymentMethod: string | null;
    paymentDate: string;
    referenceNumber: string | null;
    notes: string | null;
    createdBy: number | null;
    requestId: string | null;
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const s = await this.detectSchema(conn);

      if (s.hasRequestId && input.requestId) {
        const [dup] = await conn.query<RowDataPacket[]>(
          'SELECT id FROM supplier_payments WHERE request_id = ? LIMIT 1',
          [input.requestId],
        );
        const existingId = dup?.[0]?.id;
        if (existingId) return Number(existingId);
      }

      const openSessionId = await this.getOpenCashSessionId(conn);

      if (openSessionId) {
        const [supRows] = await conn.query<RowDataPacket[]>(
          'SELECT COALESCE(company_name, name, "") as n FROM suppliers WHERE id = ? LIMIT 1',
          [input.supplierId],
        );
        const supplierName = String(supRows?.[0]?.n ?? '');
        await this.insertCashExpense(conn, {
          cashSessionId: openSessionId,
          amount: input.paymentAmount,
          concept: `Pago proveedor ${supplierName || input.supplierId}`,
          notes: input.notes,
          referenceNumber: input.referenceNumber,
          createdBy: input.createdBy,
        });
      }

      const cols: string[] = [
        'supplier_id',
        'purchase_order_id',
        'payment_amount',
        'payment_method',
        'payment_date',
        'reference_number',
        'notes',
        'cash_session_id',
        'created_by',
        'created_at',
      ];
      const values: string[] = ['?', '?', '?', '?', '?', '?', '?', '?', '?', 'NOW()'];
      const params: Array<string | number | null> = [
        input.supplierId,
        input.purchaseOrderId,
        asMoney(input.paymentAmount),
        input.paymentMethod,
        input.paymentDate,
        input.referenceNumber,
        input.notes,
        openSessionId,
        input.createdBy,
      ];

      if (s.hasRequestId) {
        cols.splice(cols.length - 1, 0, 'request_id');
        values.splice(values.length - 1, 0, '?');
        params.splice(params.length - 1, 0, input.requestId);
      }
      if (s.hasStatus) {
        cols.splice(cols.length - 1, 0, 'status');
        values.splice(values.length - 1, 0, '?');
        params.splice(params.length - 1, 0, 'active');
      }

      const [result] = await conn.execute(
        `
        INSERT INTO supplier_payments (${cols.join(', ')})
        VALUES (${values.join(', ')})
        `,
        params,
      );
      const anyRes = result as unknown as { insertId?: number };
      const id = Number(anyRes.insertId ?? 0);

      if (input.purchaseOrderId) {
        await this.recalcPurchaseOrderPaymentStatus(conn, s, input.purchaseOrderId);
      }
      return id;
    } finally {
      await conn.end();
    }
  }

  async voidPayment(input: {
    empresaId: number;
    id: number;
    reason: string;
    voidedBy: number | null;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const s = await this.detectSchema(conn);

      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, supplier_id, purchase_order_id, payment_amount, cash_session_id, reference_number
        FROM supplier_payments
        WHERE id = ?
        LIMIT 1
        `,
        [input.id],
      );
      const p = rows?.[0];
      if (!p) return false;

      const purchaseOrderId = p.purchase_order_id === undefined || p.purchase_order_id === null ? null : Number(p.purchase_order_id);
      const amount = asMoney(p.payment_amount);
      const cashSessionId = p.cash_session_id === undefined || p.cash_session_id === null ? null : Number(p.cash_session_id);
      const referenceNumber = p.reference_number === undefined || p.reference_number === null ? null : String(p.reference_number);

      if (s.hasStatus) {
        const [result] = await conn.execute(
          `
          UPDATE supplier_payments
          SET status = 'voided', notes = CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'') = '' THEN '' ELSE '\n' END, ?)
          WHERE id = ?
          `,
          [input.reason, input.id],
        );
        const anyRes = result as unknown as { affectedRows?: number };
        const changed = Number(anyRes.affectedRows ?? 0) > 0;
        if (!changed) return false;
      } else {
        const [result] = await conn.execute('DELETE FROM supplier_payments WHERE id = ?', [input.id]);
        const anyRes = result as unknown as { affectedRows?: number };
        const changed = Number(anyRes.affectedRows ?? 0) > 0;
        if (!changed) return false;
      }

      if (cashSessionId && s.hasCashIncomeTable) {
        await this.insertCashIncome(conn, {
          cashSessionId,
          amount,
          concept: 'Reverso pago proveedor',
          notes: input.reason,
          referenceNumber,
          createdBy: input.voidedBy,
        });
      }

      if (purchaseOrderId) {
        await this.recalcPurchaseOrderPaymentStatus(conn, s, purchaseOrderId);
      }
      return true;
    } finally {
      await conn.end();
    }
  }
}

