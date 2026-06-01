import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

type InvoiceItemInput = {
  itemType: 'manual' | 'product' | 'service';
  productId: number | null;
  description: string;
  quantity: number;
  unitPrice: number;
  totalPrice: number;
};

type PaymentInput = {
  paymentAmount: number;
  paymentMethod: string;
  paymentDate: string;
  referenceNumber: string | null;
  notes: string | null;
  createdBy: number | null;
};

export type InvoiceListRow = {
  id: number;
  invoiceNumber: string;
  clientId: number;
  clientName: string;
  invoiceDate: string;
  totalAmount: number;
  paidAmount: number;
  pendingAmount: number;
  paymentStatus: 'pending' | 'partial' | 'paid';
  status: 'draft' | 'sent' | 'paid' | 'cancelled';
};

export type InvoiceItemRow = {
  id: number;
  itemType: 'manual' | 'product' | 'service';
  productId: number | null;
  description: string;
  quantity: number;
  unitPrice: number;
  totalPrice: number;
};

export type InvoicePaymentRow = {
  id: number;
  paymentAmount: number;
  paymentMethod: string;
  paymentDate: string;
  referenceNumber: string | null;
  notes: string | null;
  cashSessionId: number | null;
};

export type InvoiceRow = InvoiceListRow & {
  dueDate: string | null;
  documentType: string | null;
  subtotal: number;
  discountAmount: number;
  taxAmount: number;
  notes: string | null;
  termsConditions: string | null;
  createdAt: string;
  cancelledAt: string | null;
  cancellationReason: string | null;
  items: InvoiceItemRow[];
  payments: InvoicePaymentRow[];
};

function asMoney(v: unknown): number {
  const n = Number(v ?? 0);
  if (!Number.isFinite(n)) return 0;
  return Math.round(n * 100) / 100;
}

function parseInvoiceNumber(current: string): { prefix: string; digitsLen: number; num: number } {
  const trimmed = (current ?? '').trim();
  if (!trimmed) return { prefix: 'FAC-', digitsLen: 5, num: 0 };
  const m = trimmed.match(/^([^\d]*)(\d+)$/);
  if (m) {
    return { prefix: m[1] || 'FAC-', digitsLen: m[2].length || 5, num: Number(m[2]) || 0 };
  }
  if (/^\d+$/.test(trimmed)) {
    return { prefix: 'FAC-', digitsLen: Math.max(1, trimmed.length), num: Number(trimmed) || 0 };
  }
  return { prefix: 'FAC-', digitsLen: 5, num: 0 };
}

@Injectable()
export class InvoicesDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS invoices (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          invoice_number VARCHAR(50) NOT NULL,
          client_id INT(10) UNSIGNED NOT NULL,
          document_type VARCHAR(50) NULL,
          invoice_date DATETIME NOT NULL,
          due_date DATE NULL,
          subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          pending_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          payment_status ENUM('pending','partial','paid') NOT NULL DEFAULT 'pending',
          status ENUM('draft','sent','paid','cancelled') NOT NULL DEFAULT 'draft',
          notes TEXT NULL,
          terms_conditions TEXT NULL,
          created_by INT(10) UNSIGNED NULL,
          cancelled_by INT(10) UNSIGNED NULL,
          cancellation_reason TEXT NULL,
          cancelled_at DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS invoice_items (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          invoice_id INT(10) UNSIGNED NOT NULL,
          product_id INT(10) UNSIGNED NULL,
          item_type ENUM('manual','product','service') NOT NULL DEFAULT 'manual',
          description VARCHAR(255) NOT NULL,
          quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
          unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          KEY idx_invoice_id (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query('SELECT product_id FROM invoice_items LIMIT 1');
    } catch {
      try {
        await conn.query('ALTER TABLE invoice_items ADD COLUMN product_id INT(10) UNSIGNED NULL AFTER invoice_id');
      } catch {
      }
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS invoice_payments (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          invoice_id INT(10) UNSIGNED NOT NULL,
          payment_amount DECIMAL(12,2) NOT NULL,
          payment_method VARCHAR(100) NOT NULL,
          payment_date DATETIME NOT NULL,
          reference_number VARCHAR(100) NULL,
          notes TEXT NULL,
          cash_session_id INT(10) UNSIGNED NULL,
          created_by INT(10) UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          KEY idx_invoice_id (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query('SELECT cash_session_id FROM invoice_payments LIMIT 1');
    } catch {
      try {
        await conn.query('ALTER TABLE invoice_payments ADD COLUMN cash_session_id INT(10) UNSIGNED NULL AFTER notes');
      } catch {
      }
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS tenant_counters (
          id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
          tenant_id INT(11) NOT NULL DEFAULT 1,
          entity VARCHAR(64) NOT NULL,
          counter INT(11) NOT NULL DEFAULT 0,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
          UNIQUE KEY uq_tenant_entity (tenant_id, entity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS cash_sessions (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          opening_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          closing_date DATETIME NULL,
          opened_by INT(10) UNSIGNED NULL,
          closed_by INT(10) UNSIGNED NULL,
          initial_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          final_amount DECIMAL(12,2) NULL,
          status ENUM('open','closed') NOT NULL DEFAULT 'open',
          system_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          physical_count DECIMAL(12,2) NULL,
          difference DECIMAL(12,2) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS cash_income (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          cash_session_id INT(10) UNSIGNED NOT NULL,
          income_type VARCHAR(50) NULL,
          concept_id INT(11) NULL,
          concept VARCHAR(255) NULL,
          amount DECIMAL(12,2) NOT NULL,
          payment_method VARCHAR(100) NULL,
          reference_number VARCHAR(100) NULL,
          notes TEXT NULL,
          created_by INT(10) UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          KEY idx_cash_session_id (cash_session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS cash_expenses (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          cash_session_id INT(10) UNSIGNED NOT NULL,
          concept VARCHAR(255) NULL,
          amount DECIMAL(12,2) NOT NULL,
          notes TEXT NULL,
          reference_number VARCHAR(100) NULL,
          created_by INT(10) UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          KEY idx_cash_session_id (cash_session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }
  }

  private async getOpenCashSessionId(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      const [rows] = await conn.query<RowDataPacket[]>(
        `SELECT id FROM cash_sessions WHERE status = 'open' ORDER BY opening_date DESC LIMIT 1`,
      );
      const id = Number(rows?.[0]?.id ?? 0);
      return id > 0 ? id : null;
    } catch {
      return null;
    }
  }

  private async getNextSequence(conn: Awaited<ReturnType<typeof createTenantConnection>>, entity: string, startAt: number) {
    const tenantId = 1;
    const startBase = Math.max(1, Math.floor(startAt));
    try {
      await conn.execute(
        `
        INSERT INTO tenant_counters (tenant_id, entity, counter, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)
        `,
        [tenantId, entity, Math.max(0, startBase - 1)],
      );
    } catch {
    }
    try {
      await conn.execute('UPDATE tenant_counters SET counter = LAST_INSERT_ID(counter + 1), updated_at = NOW() WHERE tenant_id = ? AND entity = ?', [
        tenantId,
        entity,
      ]);
      const [rows] = await conn.query<RowDataPacket[]>('SELECT LAST_INSERT_ID() as v');
      const v = Number(rows?.[0]?.v ?? 0);
      return v > 0 ? v : startBase;
    } catch {
      return startBase;
    }
  }

  private async nextInvoiceNumber(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    let current = '';
    try {
      const [rows] = await conn.query<RowDataPacket[]>(
        'SELECT invoice_number FROM invoices ORDER BY id DESC LIMIT 1',
      );
      current = String(rows?.[0]?.invoice_number ?? '').trim();
    } catch {
      current = '';
    }
    const parsed = parseInvoiceNumber(current);
    const next = await this.getNextSequence(conn, 'invoices', parsed.num + 1);
    return `${parsed.prefix}${String(next).padStart(parsed.digitsLen, '0')}`;
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
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()
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
      await conn.query('SELECT current_stock FROM inventory_products LIMIT 1');
    } catch {
      try {
        await conn.query('ALTER TABLE inventory_products ADD COLUMN current_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00');
      } catch {
      }
    }

    try {
      await conn.query('SELECT is_active FROM inventory_products LIMIT 1');
    } catch {
      try {
        await conn.query('ALTER TABLE inventory_products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
      } catch {
      }
    }
  }

  async list(input: {
    empresaId: number;
    search: string;
    status: string;
    paymentStatus: string;
    limit: number;
    offset: number;
  }): Promise<{ rows: InvoiceListRow[]; total: number }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const search = input.search.trim();
      const where: string[] = [];
      const params: Array<string | number> = [];

      if (input.status.trim()) {
        where.push('i.status = ?');
        params.push(input.status.trim());
      }
      if (input.paymentStatus.trim()) {
        where.push('i.payment_status = ?');
        params.push(input.paymentStatus.trim());
      }
      if (search) {
        const sp = `%${search}%`;
        where.push(
          '(i.invoice_number LIKE ? OR CAST(i.id AS CHAR) LIKE ? OR c.first_name LIKE ? OR c.company_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)',
        );
        params.push(sp, sp, sp, sp, sp, sp);
      }
      const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';

      const [countRows] = await conn.query<RowDataPacket[]>(
        `
        SELECT COUNT(*) as total
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        ${whereSql}
        `,
        params,
      );
      const total = Number(countRows?.[0]?.total ?? 0);

      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT
          i.id,
          i.invoice_number,
          i.client_id,
          i.invoice_date,
          i.total_amount,
          i.paid_amount,
          i.pending_amount,
          i.payment_status,
          i.status,
          COALESCE(c.company_name, '') as company_name,
          COALESCE(c.first_name, '') as first_name
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        ${whereSql}
        ORDER BY i.id DESC
        LIMIT ? OFFSET ?
        `,
        [...params, input.limit, input.offset],
      );

      return {
        total,
        rows: (rows ?? []).map((r) => {
          const clientName = String(r.company_name ?? '').trim() || String(r.first_name ?? '').trim() || `Cliente #${Number(r.client_id ?? 0)}`;
          return {
            id: Number(r.id),
            invoiceNumber: String(r.invoice_number ?? ''),
            clientId: Number(r.client_id ?? 0),
            clientName,
            invoiceDate: String(r.invoice_date ?? ''),
            totalAmount: asMoney(r.total_amount),
            paidAmount: asMoney(r.paid_amount),
            pendingAmount: asMoney(r.pending_amount),
            paymentStatus: String(r.payment_status ?? 'pending') as 'pending' | 'partial' | 'paid',
            status: String(r.status ?? 'draft') as 'draft' | 'sent' | 'paid' | 'cancelled',
          };
        }),
      };
    } finally {
      await conn.end();
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<InvoiceRow | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT
          i.*,
          COALESCE(c.company_name, '') as company_name,
          COALESCE(c.first_name, '') as first_name
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE i.id = ?
        LIMIT 1
        `,
        [input.id],
      );
      const r = rows?.[0];
      if (!r) return null;

      const [itemRows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, item_type, product_id, description, quantity, unit_price, total_price
        FROM invoice_items
        WHERE invoice_id = ?
        ORDER BY id ASC
        `,
        [input.id],
      );
      const items: InvoiceItemRow[] = (itemRows ?? []).map((it) => ({
        id: Number(it.id),
        itemType: String(it.item_type ?? 'manual') as 'manual' | 'product' | 'service',
        productId: it.product_id === undefined || it.product_id === null ? null : Number(it.product_id),
        description: String(it.description ?? ''),
        quantity: Number(it.quantity ?? 0),
        unitPrice: asMoney(it.unit_price),
        totalPrice: asMoney(it.total_price),
      }));

      const [payRows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, payment_amount, payment_method, payment_date, reference_number, notes, cash_session_id
        FROM invoice_payments
        WHERE invoice_id = ?
        ORDER BY id DESC
        `,
        [input.id],
      );
      const payments: InvoicePaymentRow[] = (payRows ?? []).map((p) => ({
        id: Number(p.id),
        paymentAmount: asMoney(p.payment_amount),
        paymentMethod: String(p.payment_method ?? ''),
        paymentDate: String(p.payment_date ?? ''),
        referenceNumber: p.reference_number === undefined || p.reference_number === null ? null : String(p.reference_number),
        notes: p.notes === undefined || p.notes === null ? null : String(p.notes),
        cashSessionId: p.cash_session_id === undefined || p.cash_session_id === null ? null : Number(p.cash_session_id),
      }));

      const clientName = String(r.company_name ?? '').trim() || String(r.first_name ?? '').trim() || `Cliente #${Number(r.client_id ?? 0)}`;
      return {
        id: Number(r.id),
        invoiceNumber: String(r.invoice_number ?? ''),
        clientId: Number(r.client_id ?? 0),
        clientName,
        invoiceDate: String(r.invoice_date ?? ''),
        dueDate: r.due_date === undefined || r.due_date === null ? null : String(r.due_date),
        documentType: r.document_type === undefined || r.document_type === null ? null : String(r.document_type),
        subtotal: asMoney(r.subtotal),
        discountAmount: asMoney(r.discount_amount),
        taxAmount: asMoney(r.tax_amount),
        totalAmount: asMoney(r.total_amount),
        paidAmount: asMoney(r.paid_amount),
        pendingAmount: asMoney(r.pending_amount),
        paymentStatus: String(r.payment_status ?? 'pending') as 'pending' | 'partial' | 'paid',
        status: String(r.status ?? 'draft') as 'draft' | 'sent' | 'paid' | 'cancelled',
        notes: r.notes === undefined || r.notes === null ? null : String(r.notes),
        termsConditions: r.terms_conditions === undefined || r.terms_conditions === null ? null : String(r.terms_conditions),
        createdAt: String(r.created_at ?? ''),
        cancelledAt: r.cancelled_at === undefined || r.cancelled_at === null ? null : String(r.cancelled_at),
        cancellationReason:
          r.cancellation_reason === undefined || r.cancellation_reason === null ? null : String(r.cancellation_reason),
        items,
        payments,
      };
    } finally {
      await conn.end();
    }
  }

  async createInvoice(input: {
    empresaId: number;
    clientId: number;
    documentType: string | null;
    invoiceDate: string;
    dueDate: string | null;
    notes: string | null;
    termsConditions: string | null;
    subtotal: number;
    taxAmount: number;
    totalAmount: number;
    status: 'draft' | 'sent' | 'paid' | 'cancelled';
    createdBy: number | null;
    items: InvoiceItemInput[];
    payments: PaymentInput[];
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const productNeeds = new Map<number, number>();
      for (const it of input.items ?? []) {
        if (it.itemType !== 'product') continue;
        const productId = Number(it.productId ?? 0);
        if (!Number.isFinite(productId) || productId <= 0) throw new Error('INVALID_PRODUCT_ID');
        const qty = asMoney(it.quantity);
        if (qty <= 0) continue;
        productNeeds.set(productId, asMoney((productNeeds.get(productId) ?? 0) + qty));
      }
      const hasProductItems = productNeeds.size > 0;
      if (hasProductItems) {
        await this.ensureInventorySchema(conn);
      }

      const invoiceNumber = await this.nextInvoiceNumber(conn);

      const openCashSessionId = input.payments.length > 0 ? await this.getOpenCashSessionId(conn) : null;
      if (input.payments.length > 0 && !openCashSessionId) {
        throw new Error('NO_OPEN_CASH');
      }

      const paid = asMoney(input.payments.reduce((a, p) => a + asMoney(p.paymentAmount), 0));
      const total = asMoney(input.totalAmount);
      const paidClamped = Math.min(Math.max(0, paid), total);
      const pending = asMoney(total - paidClamped);
      const paymentStatus: 'pending' | 'partial' | 'paid' =
        paidClamped <= 0 ? 'pending' : pending <= 0 ? 'paid' : 'partial';
      const status: 'draft' | 'sent' | 'paid' | 'cancelled' = paymentStatus === 'paid' ? 'paid' : input.status;

      await conn.beginTransaction();
      try {
        if (hasProductItems) {
          for (const [productId, qty] of productNeeds.entries()) {
            const [rows] = await conn.query<RowDataPacket[]>(
              'SELECT id, current_stock, is_active FROM inventory_products WHERE id = ? LIMIT 1 FOR UPDATE',
              [productId],
            );
            const r = rows?.[0];
            if (!r || Number(r.is_active ?? 1) !== 1) throw new Error('PRODUCT_NOT_FOUND');
            const current = asMoney(r.current_stock);
            if (current < asMoney(qty)) throw new Error('INSUFFICIENT_STOCK');
          }
        }

        const [result] = await conn.execute(
          `
          INSERT INTO invoices (
            invoice_number, client_id, document_type, invoice_date, due_date,
            subtotal, discount_amount, tax_amount, total_amount,
            paid_amount, pending_amount, payment_status, status,
            notes, terms_conditions, created_by, created_at, updated_at
          )
          VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
          `,
          [
            invoiceNumber,
            input.clientId,
            input.documentType,
            input.invoiceDate,
            input.dueDate,
            asMoney(input.subtotal),
            asMoney(input.taxAmount),
            total,
            paidClamped,
            pending,
            paymentStatus,
            status,
            input.notes,
            input.termsConditions,
            input.createdBy,
          ],
        );
        const anyResult = result as { insertId?: number };
        const invoiceId = Number(anyResult.insertId ?? 0);

        for (const it of input.items) {
          await conn.execute(
            `
            INSERT INTO invoice_items (invoice_id, product_id, item_type, description, quantity, unit_price, total_price, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            `,
            [
              invoiceId,
              it.productId,
              it.itemType,
              it.description,
              it.quantity,
              asMoney(it.unitPrice),
              asMoney(it.totalPrice),
            ],
          );
        }

        if (hasProductItems) {
          for (const [productId, qty] of productNeeds.entries()) {
            await conn.execute(
              'UPDATE inventory_products SET current_stock = current_stock - ? WHERE id = ? LIMIT 1',
              [asMoney(qty), productId],
            );
          }
          for (const it of input.items) {
            if (it.itemType !== 'product') continue;
            const productId = Number(it.productId ?? 0);
            const qty = asMoney(it.quantity);
            if (qty <= 0) continue;
            await conn.execute(
              `
              INSERT INTO inventory_movements (product_id, movement_type, quantity, reference_type, reference_id, notes, created_by, created_at)
              VALUES (?, 'out', ?, 'invoice', ?, ?, ?, NOW())
              `,
              [productId, qty, invoiceId, it.description, input.createdBy],
            );
          }
        }

        for (const p of input.payments) {
          await conn.execute(
            `
            INSERT INTO invoice_payments (invoice_id, payment_amount, payment_method, payment_date, reference_number, notes, cash_session_id, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            `,
            [
              invoiceId,
              asMoney(p.paymentAmount),
              p.paymentMethod,
              p.paymentDate,
              p.referenceNumber,
              p.notes,
              openCashSessionId,
              p.createdBy,
            ],
          );

          await conn.execute(
            `
            INSERT INTO cash_income (cash_session_id, income_type, concept_id, concept, amount, payment_method, reference_number, notes, created_by, created_at)
            VALUES (?, 'invoice_payment', ?, ?, ?, ?, ?, ?, ?, NOW())
            `,
            [
              openCashSessionId,
              invoiceId,
              `Pago de factura ${invoiceNumber}`,
              asMoney(p.paymentAmount),
              p.paymentMethod,
              p.referenceNumber,
              p.notes,
              p.createdBy,
            ],
          );
        }

        await conn.commit();
        return invoiceId;
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

  async addPayment(input: { empresaId: number; invoiceId: number; payment: PaymentInput }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const existing = await this.getById({ empresaId: input.empresaId, id: input.invoiceId });
      if (!existing) return false;
      if (existing.status === 'cancelled') return false;

      const pay = asMoney(input.payment.paymentAmount);
      if (pay <= 0) return false;

      const openCashSessionId = await this.getOpenCashSessionId(conn);
      if (!openCashSessionId) throw new Error('NO_OPEN_CASH');

      await conn.execute(
        `
        INSERT INTO invoice_payments (invoice_id, payment_amount, payment_method, payment_date, reference_number, notes, cash_session_id, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        `,
        [
          input.invoiceId,
          pay,
          input.payment.paymentMethod,
          input.payment.paymentDate,
          input.payment.referenceNumber,
          input.payment.notes,
          openCashSessionId,
          input.payment.createdBy,
        ],
      );

      await conn.execute(
        `
        INSERT INTO cash_income (cash_session_id, income_type, concept_id, concept, amount, payment_method, reference_number, notes, created_by, created_at)
        VALUES (?, 'invoice_payment', ?, ?, ?, ?, ?, ?, ?, NOW())
        `,
        [
          openCashSessionId,
          input.invoiceId,
          `Pago de factura ${existing.invoiceNumber}`,
          pay,
          input.payment.paymentMethod,
          input.payment.referenceNumber,
          input.payment.notes,
          input.payment.createdBy,
        ],
      );

      const newPaid = asMoney(existing.paidAmount + pay);
      const total = asMoney(existing.totalAmount);
      const paidClamped = Math.min(Math.max(0, newPaid), total);
      const pending = asMoney(total - paidClamped);
      const paymentStatus: 'pending' | 'partial' | 'paid' =
        paidClamped <= 0 ? 'pending' : pending <= 0 ? 'paid' : 'partial';
      const status: 'draft' | 'sent' | 'paid' | 'cancelled' = paymentStatus === 'paid' ? 'paid' : existing.status;

      await conn.execute(
        `
        UPDATE invoices
        SET paid_amount = ?, pending_amount = ?, payment_status = ?, status = ?, updated_at = NOW()
        WHERE id = ?
        `,
        [paidClamped, pending, paymentStatus, status, input.invoiceId],
      );
      return true;
    } finally {
      await conn.end();
    }
  }

  async cancel(input: {
    empresaId: number;
    invoiceId: number;
    reason: string;
    cancelledBy: number | null;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const existing = await this.getById({ empresaId: input.empresaId, id: input.invoiceId });
      if (!existing) return false;
      if (existing.status === 'cancelled') return false;

      const productNeeds = new Map<number, number>();
      for (const it of existing.items ?? []) {
        if (it.itemType !== 'product') continue;
        const productId = Number(it.productId ?? 0);
        if (!Number.isFinite(productId) || productId <= 0) continue;
        const qty = asMoney(it.quantity);
        if (qty <= 0) continue;
        productNeeds.set(productId, asMoney((productNeeds.get(productId) ?? 0) + qty));
      }
      const hasProductItems = productNeeds.size > 0;
      if (hasProductItems) {
        await this.ensureInventorySchema(conn);
      }

      await conn.beginTransaction();
      try {
        if (hasProductItems) {
          for (const productId of productNeeds.keys()) {
            await conn.query<RowDataPacket[]>(
              'SELECT id FROM inventory_products WHERE id = ? LIMIT 1 FOR UPDATE',
              [productId],
            );
          }
        }

        const [result] = await conn.execute(
          `
          UPDATE invoices
          SET status = 'cancelled',
              cancellation_reason = ?,
              cancelled_by = ?,
              cancelled_at = NOW(),
              updated_at = NOW()
          WHERE id = ? AND status != 'cancelled'
          `,
          [input.reason, input.cancelledBy, input.invoiceId],
        );
        const anyResult = result as { affectedRows?: number };
        const changed = Number(anyResult.affectedRows ?? 0) > 0;
        if (!changed) {
          await conn.rollback();
          return false;
        }

        for (const p of existing.payments) {
          const sessId = p.cashSessionId;
          if (!sessId) continue;
          await conn.execute(
            `
            INSERT INTO cash_expenses (cash_session_id, concept, amount, notes, reference_number, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            `,
            [
              sessId,
              `Reverso pago factura ${existing.invoiceNumber}`,
              asMoney(p.paymentAmount),
              input.reason,
              p.referenceNumber,
              input.cancelledBy,
            ],
          );
        }

        if (hasProductItems) {
          for (const [productId, qty] of productNeeds.entries()) {
            await conn.execute(
              'UPDATE inventory_products SET current_stock = current_stock + ? WHERE id = ? LIMIT 1',
              [asMoney(qty), productId],
            );
          }
          for (const it of existing.items) {
            if (it.itemType !== 'product') continue;
            const productId = Number(it.productId ?? 0);
            const qty = asMoney(it.quantity);
            if (qty <= 0) continue;
            await conn.execute(
              `
              INSERT INTO inventory_movements (product_id, movement_type, quantity, reference_type, reference_id, notes, created_by, created_at)
              VALUES (?, 'in', ?, 'invoice_cancel', ?, ?, ?, NOW())
              `,
              [productId, qty, existing.id, input.reason, input.cancelledBy],
            );
          }
        }

        await conn.commit();
        return true;
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
