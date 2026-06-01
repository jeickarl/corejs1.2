import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

export type BillingReportRow = {
  id: number;
  invoiceNumber: string;
  clientName: string;
  invoiceDate: string;
  totalAmount: number;
  paidAmount: number;
  pendingAmount: number;
  paymentStatus: string;
  status: string;
};

export type BillingReportTotals = {
  totalAmount: number;
  paidAmount: number;
  pendingAmount: number;
  cancelledAmount: number;
};

export type SuppliersReportRow = {
  supplierId: number;
  supplierName: string;
  ordersCount: number;
  totalAmount: number;
  paidAmount: number;
  pendingAmount: number;
};

export type ServicesReportStats = {
  totalServices: number;
  totalRevenue: number;
  averagePrice: number;
  mostPopularService: { name: string; usageCount: number } | null;
};

export type ServicesReportRow = {
  serviceId: number;
  name: string;
  categoryName: string;
  basePrice: number;
  usageCount: number;
  totalRevenue: number;
  averagePrice: number;
};

function asMoney(v: unknown): number {
  const n = Number(v ?? 0);
  if (!Number.isFinite(n)) return 0;
  return Math.round(n * 100) / 100;
}

type PurchaseOrderSchema = 'v2' | 'v1';

@Injectable()
export class ReportsDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureBillingSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `SELECT invoice_date, total_amount, paid_amount, pending_amount, payment_status, status FROM invoices LIMIT 1`,
      );
    } catch {
      try {
        await conn.query(
          `
          CREATE TABLE IF NOT EXISTS invoices (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(50) NOT NULL,
            client_id INT(10) UNSIGNED NOT NULL,
            invoice_date DATETIME NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            pending_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            KEY idx_created_at (created_at)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          `,
        );
      } catch {
      }
    }
  }

  private async detectPurchaseOrdersSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>): Promise<PurchaseOrderSchema> {
    try {
      await conn.query('SELECT po_number, total_amount, payment_status, status FROM purchase_orders LIMIT 1');
      return 'v2';
    } catch {
      return 'v1';
    }
  }

  private async ensureSuppliersSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query('SELECT id FROM suppliers LIMIT 1');
    } catch {
      try {
        await conn.query(
          `
          CREATE TABLE IF NOT EXISTS suppliers (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(255) NULL,
            name VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP()
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          `,
        );
      } catch {
      }
    }

    try {
      await conn.query('SELECT id FROM supplier_payments LIMIT 1');
    } catch {
      try {
        await conn.query(
          `
          CREATE TABLE IF NOT EXISTS supplier_payments (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT(10) UNSIGNED NOT NULL,
            purchase_order_id INT(10) UNSIGNED NULL,
            payment_amount DECIMAL(12,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            KEY idx_supplier_id (supplier_id),
            KEY idx_purchase_order_id (purchase_order_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          `,
        );
      } catch {
      }
    }
  }

  private async ensureServicesReportSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query('SELECT id FROM work_order_services LIMIT 1');
    } catch {
      try {
        await conn.query(
          `
          CREATE TABLE IF NOT EXISTS work_order_services (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            work_order_id INT NOT NULL,
            service_id INT NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
            service_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_work_order_id (work_order_id),
            KEY idx_service_id (service_id),
            KEY idx_created_at (created_at)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          `,
        );
      } catch {
      }
    }

    try {
      await conn.query('SELECT id, base_price, device_category_id FROM services LIMIT 1');
    } catch {
      try {
        await conn.query(
          `
          CREATE TABLE IF NOT EXISTS services (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            device_category_id INT(10) UNSIGNED NOT NULL,
            base_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          `,
        );
      } catch {
      }
    }

    try {
      await conn.query('SELECT id, name FROM device_categories LIMIT 1');
    } catch {
      try {
        await conn.query(
          `
          CREATE TABLE IF NOT EXISTS device_categories (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          `,
        );
      } catch {
      }
    }
  }

  async billingReport(input: {
    empresaId: number;
    from: string;
    to: string;
    status: string;
    paymentStatus: string;
    limit: number;
    offset: number;
  }): Promise<{ rows: BillingReportRow[]; total: number; totals: BillingReportTotals }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureBillingSchema(conn);
      const where: string[] = [];
      const params: Array<string | number> = [];

      where.push('DATE(i.invoice_date) BETWEEN ? AND ?');
      params.push(input.from, input.to);

      if (input.status.trim()) {
        where.push('i.status = ?');
        params.push(input.status.trim());
      }
      if (input.paymentStatus.trim()) {
        where.push('i.payment_status = ?');
        params.push(input.paymentStatus.trim());
      }

      const whereSql = `WHERE ${where.join(' AND ')}`;

      const [countRows] = await conn.query<RowDataPacket[]>(
        `
        SELECT COUNT(*) as total
        FROM invoices i
        ${whereSql}
        `,
        params,
      );
      const total = Number(countRows?.[0]?.total ?? 0);

      const [totRows] = await conn.query<RowDataPacket[]>(
        `
        SELECT
          COALESCE(SUM(i.total_amount), 0) as total_amount,
          COALESCE(SUM(i.paid_amount), 0) as paid_amount,
          COALESCE(SUM(i.pending_amount), 0) as pending_amount,
          COALESCE(SUM(CASE WHEN i.status = 'cancelled' THEN i.total_amount ELSE 0 END), 0) as cancelled_amount
        FROM invoices i
        ${whereSql}
        `,
        params,
      );
      const tr = totRows?.[0] ?? ({} as RowDataPacket);

      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT
          i.id,
          i.invoice_number,
          i.invoice_date,
          i.total_amount,
          i.paid_amount,
          i.pending_amount,
          i.payment_status,
          i.status,
          COALESCE(c.company_name, c.first_name, '') as client_name
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        ${whereSql}
        ORDER BY i.invoice_date DESC, i.id DESC
        LIMIT ? OFFSET ?
        `,
        [...params, input.limit, input.offset],
      );

      return {
        total,
        totals: {
          totalAmount: asMoney(tr.total_amount),
          paidAmount: asMoney(tr.paid_amount),
          pendingAmount: asMoney(tr.pending_amount),
          cancelledAmount: asMoney(tr.cancelled_amount),
        },
        rows: (rows ?? []).map((r) => ({
          id: Number(r.id),
          invoiceNumber: String(r.invoice_number ?? ''),
          clientName: String(r.client_name ?? ''),
          invoiceDate: String(r.invoice_date ?? ''),
          totalAmount: asMoney(r.total_amount),
          paidAmount: asMoney(r.paid_amount),
          pendingAmount: asMoney(r.pending_amount),
          paymentStatus: String(r.payment_status ?? ''),
          status: String(r.status ?? ''),
        })),
      };
    } finally {
      await conn.end();
    }
  }

  async suppliersReport(input: { empresaId: number; from: string; to: string }): Promise<{ rows: SuppliersReportRow[] }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSuppliersSchema(conn);
      const schema = await this.detectPurchaseOrdersSchema(conn);

      const totalExpr = schema === 'v2' ? 'po.total_amount' : 'po.grand_total';

      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT
          po.supplier_id,
          COALESCE(s.company_name, s.name, '') as supplier_name,
          COUNT(DISTINCT po.id) as orders_count,
          COALESCE(SUM(${totalExpr}), 0) as total_amount,
          COALESCE(SUM(COALESCE(pp.paid_amount, 0)), 0) as paid_amount,
          COALESCE(SUM(${totalExpr} - COALESCE(pp.paid_amount, 0)), 0) as pending_amount
        FROM purchase_orders po
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        LEFT JOIN (
          SELECT
            purchase_order_id,
            COALESCE(SUM(CASE WHEN status != 'voided' THEN payment_amount ELSE 0 END), 0) as paid_amount
          FROM supplier_payments
          GROUP BY purchase_order_id
        ) pp ON pp.purchase_order_id = po.id
        WHERE DATE(po.order_date) BETWEEN ? AND ? AND po.status != 'cancelled'
        GROUP BY po.supplier_id
        ORDER BY total_amount DESC
        `,
        [input.from, input.to],
      );

      return {
        rows: (rows ?? []).map((r) => ({
          supplierId: Number(r.supplier_id ?? 0),
          supplierName: String(r.supplier_name ?? ''),
          ordersCount: Number(r.orders_count ?? 0),
          totalAmount: asMoney(r.total_amount),
          paidAmount: asMoney(r.paid_amount),
          pendingAmount: asMoney(r.pending_amount),
        })),
      };
    } finally {
      await conn.end();
    }
  }

  async servicesReport(input: {
    empresaId: number;
    from: string;
    to: string;
    serviceId: number | null;
    categoryId: number | null;
  }): Promise<{ stats: ServicesReportStats; rows: ServicesReportRow[] }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureServicesReportSchema(conn);
      const where: string[] = ['DATE(wo.created_at) BETWEEN ? AND ?'];
      const params: Array<string | number> = [input.from, input.to];

      if (input.serviceId) {
        where.push('wos.service_id = ?');
        params.push(input.serviceId);
      }
      if (input.categoryId) {
        where.push('s.device_category_id = ?');
        params.push(input.categoryId);
      }

      const whereSql = `WHERE ${where.join(' AND ')}`;

      let stats: ServicesReportStats = {
        totalServices: 0,
        totalRevenue: 0,
        averagePrice: 0,
        mostPopularService: null,
      };

      try {
        const [rows] = await conn.query<RowDataPacket[]>(
          `
          SELECT
            COUNT(DISTINCT wos.service_id) as total_services,
            COALESCE(SUM(wos.total_price), 0) as total_revenue,
            COALESCE(AVG(wos.service_price), 0) as average_price
          FROM work_order_services wos
          INNER JOIN work_orders wo ON wos.work_order_id = wo.id
          INNER JOIN services s ON wos.service_id = s.id
          ${whereSql}
          `,
          params,
        );
        const r = rows?.[0] ?? ({} as RowDataPacket);
        stats.totalServices = Number(r.total_services ?? 0);
        stats.totalRevenue = asMoney(r.total_revenue);
        stats.averagePrice = asMoney(r.average_price);
      } catch {
      }

      try {
        const [rows] = await conn.query<RowDataPacket[]>(
          `
          SELECT s.name, COUNT(*) as usage_count
          FROM work_order_services wos
          INNER JOIN work_orders wo ON wos.work_order_id = wo.id
          INNER JOIN services s ON wos.service_id = s.id
          ${whereSql}
          GROUP BY s.id, s.name
          ORDER BY usage_count DESC
          LIMIT 1
          `,
          params,
        );
        const r = rows?.[0];
        if (r) {
          stats.mostPopularService = { name: String(r.name ?? ''), usageCount: Number(r.usage_count ?? 0) };
        }
      } catch {
      }

      let rows: ServicesReportRow[] = [];
      try {
        const [rws] = await conn.query<RowDataPacket[]>(
          `
          SELECT
            s.id as service_id,
            s.name,
            COALESCE(s.base_price, 0) as base_price,
            COALESCE(dc.name,'') as category_name,
            COUNT(*) as usage_count,
            COALESCE(SUM(wos.total_price), 0) as total_revenue,
            COALESCE(AVG(wos.service_price), 0) as average_price
          FROM work_order_services wos
          INNER JOIN work_orders wo ON wos.work_order_id = wo.id
          INNER JOIN services s ON wos.service_id = s.id
          LEFT JOIN device_categories dc ON dc.id = s.device_category_id
          ${whereSql}
          GROUP BY s.id, s.name, s.base_price, dc.name
          ORDER BY usage_count DESC
          LIMIT 10
          `,
          params,
        );
        rows = (rws ?? []).map((r) => ({
          serviceId: Number(r.service_id ?? 0),
          name: String(r.name ?? ''),
          categoryName: String(r.category_name ?? ''),
          basePrice: asMoney(r.base_price),
          usageCount: Number(r.usage_count ?? 0),
          totalRevenue: asMoney(r.total_revenue),
          averagePrice: asMoney(r.average_price),
        }));
      } catch {
        rows = [];
      }

      return { stats, rows };
    } finally {
      await conn.end();
    }
  }
}
