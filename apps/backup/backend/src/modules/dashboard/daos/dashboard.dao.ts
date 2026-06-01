import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

export type DashboardLowStockRow = {
  name: string;
  currentStock: number;
  minStock: number;
};

export type DashboardOrderRow = {
  id: number;
  orderNumber: string;
  clientName: string;
  phone: string;
  deviceBrand: string;
  deviceModel: string;
  status: string;
  createdAt: string;
  completedAt: string;
  totalAmount: number;
  daysOpen: number;
  priority: string;
  accessories: string;
};

export type DashboardSummaryRow = {
  totalOrders: number;
  pendingOrders: number;
  totalClients: number;
  revenue: number;
  ordersTrendPct: number;
  salesTrendPct: number;
  lowStockItems: DashboardLowStockRow[];
  recentOrders: DashboardOrderRow[];
  stagnantOrders: DashboardOrderRow[];
  readyOrders: DashboardOrderRow[];
};

export type DashboardSalesChartRow = {
  labels: string[];
  current: number[];
  previous: number[];
  kpi: { avg: number; max: number; total: number };
};

export type DashboardOrdersChartRow = {
  labels: string[];
  values: number[];
};

export type DashboardTopProductRow = {
  productId: number;
  name: string;
  quantity: number;
  revenue: number;
};

export type DashboardTopClientRow = {
  clientId: number;
  name: string;
  invoicesCount: number;
  totalAmount: number;
};

export type DashboardAnalyticsRow = {
  topProducts: DashboardTopProductRow[];
  topClients: DashboardTopClientRow[];
  alerts: {
    lowStockCount: number;
    waitingApprovalCount: number;
  };
};

export type DashboardNoteRow = {
  content: string;
  updatedAt: string;
};

export type DashboardSearchItemRow = {
  type: 'order' | 'client' | 'product';
  url: string;
  title: string;
  subtitle: string;
  icon: string;
};

function pad2(n: number): string {
  return String(n).padStart(2, '0');
}

function fmtDdMm(date: Date): string {
  return `${pad2(date.getDate())}/${pad2(date.getMonth() + 1)}`;
}

function startOfDay(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0, 0);
}

function subDays(d: Date, days: number): Date {
  const x = new Date(d);
  x.setDate(x.getDate() - days);
  return x;
}

function buildDateSeries(days: number, offsetDays: number): Array<{ date: Date; label: string }> {
  const today = startOfDay(new Date());
  const end = subDays(today, offsetDays);
  const out: Array<{ date: Date; label: string }> = [];
  for (let i = days - 1; i >= 0; i--) {
    const dt = subDays(end, i);
    out.push({ date: dt, label: fmtDdMm(dt) });
  }
  return out;
}

@Injectable()
export class DashboardDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureUserNotesMasterSchema() {
    await this.masterPool.query(
      `
      CREATE TABLE IF NOT EXISTS dashboard_user_notes (
        tenant_id INT NOT NULL,
        user_id INT NOT NULL,
        content TEXT,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (tenant_id, user_id)
      )
      `,
    );
  }

  private async safeQueryNumber(conn: Awaited<ReturnType<typeof createTenantConnection>>, sql: string, params: unknown[]) {
    try {
      const [rows] = await conn.query<RowDataPacket[]>(sql, params);
      return Number(rows?.[0]?.v ?? 0);
    } catch {
      return 0;
    }
  }

  async summary(input: { empresaId: number; revenuePeriod: 'day' | 'week' | 'month' | 'year' | 'total' }): Promise<DashboardSummaryRow> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      const totalOrders = await this.safeQueryNumber(
        conn,
        "SELECT COUNT(*) as v FROM work_orders WHERE status != 'cancelled'",
        [],
      );
      const pendingOrders = await this.safeQueryNumber(
        conn,
        "SELECT COUNT(*) as v FROM work_orders WHERE status NOT IN ('completed', 'delivered', 'cancelled')",
        [],
      );
      const totalClients = await this.safeQueryNumber(conn, 'SELECT COUNT(*) as v FROM clients', []);

      let revenueSql = "SELECT COALESCE(SUM(total_amount), 0) as v FROM invoices WHERE status NOT IN ('cancelled', 'draft')";
      if (input.revenuePeriod === 'day') {
        revenueSql += ' AND DATE(created_at) = CURDATE()';
      } else if (input.revenuePeriod === 'week') {
        revenueSql += ' AND YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)';
      } else if (input.revenuePeriod === 'month') {
        revenueSql += ' AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())';
      } else if (input.revenuePeriod === 'year') {
        revenueSql += ' AND YEAR(created_at) = YEAR(NOW())';
      }
      const revenue = await this.safeQueryNumber(conn, revenueSql, []);

      const ordersCurrent7 = await this.safeQueryNumber(
        conn,
        'SELECT COUNT(*) as v FROM work_orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
        [],
      );
      const ordersPrev7 = await this.safeQueryNumber(
        conn,
        'SELECT COUNT(*) as v FROM work_orders WHERE created_at BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY)',
        [],
      );
      const ordersTrendPct = ordersPrev7 > 0 ? ((ordersCurrent7 - ordersPrev7) / ordersPrev7) * 100 : 100;

      const salesCurrent7 = await this.safeQueryNumber(
        conn,
        "SELECT COALESCE(SUM(total_amount), 0) as v FROM invoices WHERE status != 'cancelled' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
        [],
      );
      const salesPrev7 = await this.safeQueryNumber(
        conn,
        "SELECT COALESCE(SUM(total_amount), 0) as v FROM invoices WHERE status != 'cancelled' AND created_at BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY)",
        [],
      );
      const salesTrendPct = salesPrev7 > 0 ? ((salesCurrent7 - salesPrev7) / salesPrev7) * 100 : 100;

      let lowStockItems: DashboardLowStockRow[] = [];
      try {
        const [rows] = await conn.query<RowDataPacket[]>(
          `
          SELECT name, current_stock, min_stock
          FROM inventory_products
          WHERE current_stock <= min_stock AND is_active = 1
          ORDER BY current_stock ASC
          LIMIT 5
          `,
        );
        lowStockItems = (rows ?? []).map((r) => ({
          name: String(r.name ?? ''),
          currentStock: Number(r.current_stock ?? 0),
          minStock: Number(r.min_stock ?? 0),
        }));
      } catch {
        lowStockItems = [];
      }

      const recentOrders = await this.safeOrders(conn, 'recent');
      const stagnantOrders = await this.safeOrders(conn, 'stagnant');
      const readyOrders = await this.safeOrders(conn, 'ready');

      return {
        totalOrders,
        pendingOrders,
        totalClients,
        revenue,
        ordersTrendPct,
        salesTrendPct,
        lowStockItems,
        recentOrders,
        stagnantOrders,
        readyOrders,
      };
    } finally {
      await conn.end();
    }
  }

  private async safeOrders(
    conn: Awaited<ReturnType<typeof createTenantConnection>>,
    kind: 'recent' | 'stagnant' | 'ready',
  ): Promise<DashboardOrderRow[]> {
    const accessoriesSql = `
      (SELECT GROUP_CONCAT(ea.name SEPARATOR ', ')
       FROM order_equipment_accessories oea
       JOIN equipment_accessories ea ON oea.accessory_id = ea.id
       WHERE oea.order_id = o.id AND oea.is_included = 1) as accessories
    `;

    try {
      if (kind === 'recent') {
        const [rows] = await conn.query<RowDataPacket[]>(
          `
          SELECT o.id, o.order_number, o.device_brand, o.device_model, o.status, o.created_at,
                 COALESCE(c.first_name, '') as first_name, COALESCE(c.company_name, '') as company_name,
                 ${accessoriesSql}
          FROM work_orders o
          LEFT JOIN clients c ON o.client_id = c.id
          ORDER BY o.created_at DESC
          LIMIT 5
          `,
        );
        return (rows ?? []).map((r) => ({
          id: Number(r.id),
          orderNumber: String(r.order_number ?? ''),
          clientName: `${String(r.first_name ?? '').trim()}${String(r.company_name ?? '').trim() ? ` ${String(r.company_name ?? '').trim()}` : ''}`.trim(),
          phone: '',
          deviceBrand: String(r.device_brand ?? ''),
          deviceModel: String(r.device_model ?? ''),
          status: String(r.status ?? ''),
          createdAt: String(r.created_at ?? ''),
          completedAt: '',
          totalAmount: 0,
          daysOpen: 0,
          priority: '',
          accessories: String(r.accessories ?? ''),
        }));
      }

      if (kind === 'stagnant') {
        const [rows] = await conn.query<RowDataPacket[]>(
          `
          SELECT o.id, o.order_number, o.device_model, o.status, o.created_at, o.priority,
                 DATEDIFF(NOW(), o.created_at) as days_open,
                 COALESCE(c.first_name, '') as first_name, COALESCE(c.company_name, '') as company_name,
                 ${accessoriesSql}
          FROM work_orders o
          LEFT JOIN clients c ON o.client_id = c.id
          WHERE o.status NOT IN ('completed', 'delivered', 'cancelled')
            AND DATEDIFF(NOW(), o.created_at) > 3
          ORDER BY days_open DESC
          LIMIT 5
          `,
        );
        return (rows ?? []).map((r) => ({
          id: Number(r.id),
          orderNumber: String(r.order_number ?? ''),
          clientName: `${String(r.first_name ?? '').trim()}${String(r.company_name ?? '').trim() ? ` ${String(r.company_name ?? '').trim()}` : ''}`.trim(),
          phone: '',
          deviceBrand: '',
          deviceModel: String(r.device_model ?? ''),
          status: String(r.status ?? ''),
          createdAt: String(r.created_at ?? ''),
          completedAt: '',
          totalAmount: 0,
          daysOpen: Number(r.days_open ?? 0),
          priority: String(r.priority ?? ''),
          accessories: String(r.accessories ?? ''),
        }));
      }

      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT o.id, o.order_number, o.device_brand, o.device_model,
               o.completed_date as completed_at,
               COALESCE(o.final_cost, o.estimated_cost, 0) as total_amount,
               COALESCE(c.first_name, '') as first_name, COALESCE(c.company_name, '') as company_name, COALESCE(c.phone, '') as phone,
               ${accessoriesSql}
        FROM work_orders o
        LEFT JOIN clients c ON o.client_id = c.id
        WHERE o.status = 'completed'
        ORDER BY o.completed_date ASC
        LIMIT 10
        `,
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        orderNumber: String(r.order_number ?? ''),
        clientName: `${String(r.first_name ?? '').trim()}${String(r.company_name ?? '').trim() ? ` ${String(r.company_name ?? '').trim()}` : ''}`.trim(),
        phone: String(r.phone ?? ''),
        deviceBrand: String(r.device_brand ?? ''),
        deviceModel: String(r.device_model ?? ''),
        status: 'completed',
        createdAt: '',
        completedAt: String(r.completed_at ?? ''),
        totalAmount: Number(r.total_amount ?? 0),
        daysOpen: 0,
        priority: '',
        accessories: String(r.accessories ?? ''),
      }));
    } catch {
      return [];
    }
  }

  async salesChart(input: { empresaId: number; days: number }): Promise<DashboardSalesChartRow> {
    const days = Number.isFinite(input.days) && input.days > 0 ? Math.min(180, Math.max(1, input.days)) : 7;
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      const seriesNow = buildDateSeries(days, 0);
      const seriesPrev = buildDateSeries(days, days);

      const labels = seriesNow.map((s) => s.label);
      const current = await this.salesSeries(conn, seriesNow);
      const previous = await this.salesSeries(conn, seriesPrev);

      const total = current.reduce((a, b) => a + b, 0);
      const avg = current.length > 0 ? total / current.length : 0;
      const max = current.length > 0 ? Math.max(...current) : 0;

      return { labels, current, previous, kpi: { avg, max, total } };
    } finally {
      await conn.end();
    }
  }

  private async salesSeries(
    conn: Awaited<ReturnType<typeof createTenantConnection>>,
    series: Array<{ date: Date; label: string }>,
  ): Promise<number[]> {
    const start = series[0]?.date;
    const end = series[series.length - 1]?.date;
    if (!start || !end) return [];

    try {
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT DATE(created_at) as d, COALESCE(SUM(total_amount), 0) as v
        FROM invoices
        WHERE status != 'cancelled'
          AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY DATE(created_at)
        `,
        [start, end],
      );
      const map = new Map<string, number>();
      for (const r of rows ?? []) {
        const d = String(r.d ?? '');
        map.set(d, Number(r.v ?? 0));
      }
      return series.map((s) => {
        const key = `${s.date.getFullYear()}-${pad2(s.date.getMonth() + 1)}-${pad2(s.date.getDate())}`;
        return map.get(key) ?? 0;
      });
    } catch {
      return series.map(() => 0);
    }
  }

  async ordersChart(input: { empresaId: number }): Promise<DashboardOrdersChartRow> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT status, COUNT(*) as c
        FROM work_orders
        GROUP BY status
        ORDER BY c DESC
        LIMIT 20
        `,
      );
      const labels: string[] = [];
      const values: number[] = [];
      for (const r of rows ?? []) {
        labels.push(String(r.status ?? ''));
        values.push(Number(r.c ?? 0));
      }
      return { labels, values };
    } catch {
      return { labels: [], values: [] };
    } finally {
      await conn.end();
    }
  }

  async analytics(input: { empresaId: number; from: string; to: string }): Promise<DashboardAnalyticsRow> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      const lowStockCount = await this.safeQueryNumber(
        conn,
        `
        SELECT COUNT(*) as v
        FROM inventory_products
        WHERE current_stock <= min_stock AND is_active = 1
        `,
        [],
      );

      const waitingApprovalCount = await this.safeQueryNumber(
        conn,
        `
        SELECT COUNT(*) as v
        FROM work_orders
        WHERE approval_status = 'pending'
        `,
        [],
      );

      let topProducts: DashboardTopProductRow[] = [];
      try {
        const [rows] = await conn.query<RowDataPacket[]>(
          `
          SELECT
            ii.product_id,
            COALESCE(p.name, '') as name,
            COALESCE(SUM(ii.quantity), 0) as qty,
            COALESCE(SUM(ii.total_price), 0) as revenue
          FROM invoice_items ii
          INNER JOIN invoices i ON i.id = ii.invoice_id
          LEFT JOIN inventory_products p ON p.id = ii.product_id
          WHERE ii.item_type = 'product'
            AND ii.product_id IS NOT NULL
            AND i.status NOT IN ('cancelled', 'draft')
            AND DATE(i.invoice_date) BETWEEN ? AND ?
          GROUP BY ii.product_id
          ORDER BY revenue DESC
          LIMIT 10
          `,
          [input.from, input.to],
        );
        topProducts = (rows ?? []).map((r) => ({
          productId: Number(r.product_id ?? 0),
          name: String(r.name ?? ''),
          quantity: Number(r.qty ?? 0),
          revenue: Number(r.revenue ?? 0),
        }));
      } catch {
        topProducts = [];
      }

      let topClients: DashboardTopClientRow[] = [];
      try {
        const [rows] = await conn.query<RowDataPacket[]>(
          `
          SELECT
            i.client_id,
            COALESCE(c.company_name, c.first_name, '') as name,
            COUNT(*) as invoices_count,
            COALESCE(SUM(i.total_amount), 0) as total_amount
          FROM invoices i
          LEFT JOIN clients c ON c.id = i.client_id
          WHERE i.status NOT IN ('cancelled', 'draft')
            AND DATE(i.invoice_date) BETWEEN ? AND ?
          GROUP BY i.client_id
          ORDER BY total_amount DESC
          LIMIT 10
          `,
          [input.from, input.to],
        );
        topClients = (rows ?? []).map((r) => ({
          clientId: Number(r.client_id ?? 0),
          name: String(r.name ?? ''),
          invoicesCount: Number(r.invoices_count ?? 0),
          totalAmount: Number(r.total_amount ?? 0),
        }));
      } catch {
        topClients = [];
      }

      return {
        topProducts,
        topClients,
        alerts: { lowStockCount, waitingApprovalCount },
      };
    } finally {
      await conn.end();
    }
  }

  async getNotes(input: { empresaId: number; userId: number }): Promise<DashboardNoteRow> {
    try {
      await this.ensureUserNotesMasterSchema();
      const [rows] = await this.masterPool.query<RowDataPacket[]>(
        `
        SELECT content, updated_at
        FROM dashboard_user_notes
        WHERE tenant_id = ? AND user_id = ?
        LIMIT 1
        `,
        [input.empresaId, input.userId],
      );
      const r = rows?.[0];
      if (!r) return { content: '', updatedAt: '' };
      return { content: String(r.content ?? ''), updatedAt: String(r.updated_at ?? '') };
    } catch {
      return { content: '', updatedAt: '' };
    }
  }

  async saveNotes(input: { empresaId: number; userId: number; content: string }): Promise<boolean> {
    try {
      await this.ensureUserNotesMasterSchema();
      await this.masterPool.execute(
        `
        INSERT INTO dashboard_user_notes (tenant_id, user_id, content, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = NOW()
        `,
        [input.empresaId, input.userId, input.content],
      );
      return true;
    } catch {
      return false;
    }
  }

  async globalSearch(input: {
    empresaId: number;
    query: string;
    type: 'orders' | 'clients' | 'inventory';
  }): Promise<DashboardSearchItemRow[]> {
    const q = input.query.trim();
    if (q.length < 2) return [];
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      const sp = `%${q}%`;
      if (input.type === 'clients') {
        const [rows] = await conn.query<RowDataPacket[]>(
          `
          SELECT id, first_name, company_name, phone, email
          FROM clients
          WHERE first_name LIKE ? OR company_name LIKE ? OR phone LIKE ? OR email LIKE ?
          ORDER BY id DESC
          LIMIT 5
          `,
          [sp, sp, sp, sp],
        );
        return (rows ?? []).map((r) => ({
          type: 'client',
          url: `/clients/${Number(r.id)}`,
          title: String(r.company_name ?? '').trim() || String(r.first_name ?? '').trim() || `Cliente #${Number(r.id)}`,
          subtitle: [String(r.phone ?? '').trim(), String(r.email ?? '').trim()].filter(Boolean).join(' · '),
          icon: 'fa-user',
        }));
      }

      if (input.type === 'inventory') {
        const [rows] = await conn.query<RowDataPacket[]>(
          `
          SELECT id, name, description, current_stock
          FROM inventory_products
          WHERE is_active = 1 AND (name LIKE ? OR description LIKE ?)
          ORDER BY id DESC
          LIMIT 5
          `,
          [sp, sp],
        );
        return (rows ?? []).map((r) => ({
          type: 'product',
          url: `/inventory/products/${Number(r.id)}`,
          title: String(r.name ?? '').trim() || `Producto #${Number(r.id)}`,
          subtitle: `Stock: ${Number(r.current_stock ?? 0)}`,
          icon: 'fa-box',
        }));
      }

      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT o.id, o.order_number, o.device_brand, o.device_model, o.status,
               COALESCE(c.first_name, '') as first_name, COALESCE(c.company_name, '') as company_name
        FROM work_orders o
        LEFT JOIN clients c ON o.client_id = c.id
        WHERE CAST(o.id AS CHAR) LIKE ?
           OR o.order_number LIKE ?
           OR o.device_model LIKE ?
           OR o.device_brand LIKE ?
           OR c.first_name LIKE ?
           OR c.company_name LIKE ?
        ORDER BY o.id DESC
        LIMIT 5
        `,
        [sp, sp, sp, sp, sp, sp],
      );
      return (rows ?? []).map((r) => ({
        type: 'order',
        url: `/orders/${Number(r.id)}`,
        title: `Orden #${String(r.order_number ?? r.id)}`,
        subtitle: `${String(r.device_brand ?? '').trim()} ${String(r.device_model ?? '').trim()} · ${String(r.status ?? '').trim()} · ${(
          String(r.company_name ?? '').trim() || String(r.first_name ?? '').trim()
        ).trim()}`,
        icon: 'fa-tools',
      }));
    } catch {
      return [];
    } finally {
      await conn.end();
    }
  }
}
