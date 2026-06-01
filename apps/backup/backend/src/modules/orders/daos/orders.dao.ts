import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

export type OrderRow = {
  id: number;
  orderNumber: string;
  clientId: number;
  clientName: string;
  deviceTypeId: number;
  deviceTypeName: string;
  deviceBrand: string;
  deviceModel: string;
  devicePassword: string;
  serialNumber: string;
  reportedIssue: string;
  clientObservations: string;
  status: string;
  approvalStatus: string;
  approvedAt: string | null;
  approvedQuoteAmount: number | null;
  approvalComment: string | null;
  approvalSignature: string | null;
  priority: string;
  estimatedCost: number;
  finalCost: number;
  advancePayment: number;
  paymentMethod: string;
  paymentReference: string;
  technicianNotes: string;
  diagnosis: string;
  solution: string;
  estimatedCompletion: string | null;
  accessoryIds: number[];
  createdAt: string;
  updatedAt: string;
};

export type OrderListRow = {
  id: number;
  orderNumber: string;
  clientName: string;
  clientPhone: string;
  clientEmail: string;
  deviceTypeName: string;
  deviceBrand: string;
  deviceModel: string;
  serialNumber: string;
  reportedIssue: string;
  status: string;
  approvalStatus: string;
  priority: string;
  createdAt: string;
};

export type OrderStatusRow = {
  slug: string;
  name: string;
  emoji: string;
  color: string;
  sortOrder: number;
};

export type SerialLookupRow = {
  orderId: number;
  clientId: number;
  deviceTypeId: number;
  deviceBrand: string;
  deviceModel: string;
};

export type OrderStatusHistoryRow = {
  id: number;
  status: string;
  userId: number | null;
  createdAt: string;
};

export type TechnicalReportListRow = {
  id: number;
  reportTitle: string;
  createdAt: string;
  createdBy: number | null;
};

export type TechnicalReportRow = TechnicalReportListRow & {
  orderId: number;
  diagnosis: string;
  procedureTaken: string;
  introduction: string;
  conclusion: string;
  photosJson: string | null;
};

export type WorkOrderServiceRow = {
  id: number;
  workOrderId: number;
  serviceId: number;
  serviceName: string;
  quantity: number;
  servicePrice: number;
  totalPrice: number;
  createdAt: string;
};

const STATUS_SYNONYMS: Record<string, string> = {
  pendiente: 'pending',
  pending: 'pending',
  asignado: 'received',
  received: 'received',
  diagnosticando: 'diagnosing',
  diagnosing: 'diagnosing',
  esperando_repuestos: 'waiting_parts',
  waiting_parts: 'waiting_parts',
  waitingparts: 'waiting_parts',
  reparando: 'repairing',
  repairing: 'repairing',
  testeando: 'testing',
  testing: 'testing',
  completado: 'completed',
  completed: 'completed',
  entregado: 'delivered',
  delivered: 'delivered',
  cancelado: 'cancelled',
  cancelled: 'cancelled',
  canceled: 'cancelled',
  esperando_aprobacion: 'esperando_aprobacion',
  esperandoaprobacion: 'esperando_aprobacion',
  'esperando aprobacion': 'esperando_aprobacion',
  'esperando-aprobacion': 'esperando_aprobacion',
  waiting_authorization: 'esperando_aprobacion',
  'waiting approval': 'esperando_aprobacion',
};

function normalizeSlug(v: string): string {
  return v
    .trim()
    .toLowerCase()
    .replace(/\s+/g, ' ')
    .replace(/-/g, '_')
    .replace(/[áàäâ]/g, 'a')
    .replace(/[éèëê]/g, 'e')
    .replace(/[íìïî]/g, 'i')
    .replace(/[óòöô]/g, 'o')
    .replace(/[úùüû]/g, 'u');
}

function canonicalStatus(input: string): string {
  const n = normalizeSlug(input);
  return STATUS_SYNONYMS[n] ?? n;
}

@Injectable()
export class OrdersDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureColumn(
    conn: Awaited<ReturnType<typeof createTenantConnection>>,
    column: string,
    alterSql: string,
  ) {
    try {
      await conn.query(`SELECT ${column} FROM work_orders LIMIT 1`);
      return;
    } catch {
    }
    try {
      await conn.query(alterSql);
    } catch {
    }
  }

  private async ensureOrderNumber(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query('SELECT order_number FROM work_orders LIMIT 1');
      return;
    } catch {
    }
    try {
      await conn.query('ALTER TABLE work_orders ADD COLUMN order_number VARCHAR(64) NULL AFTER id');
    } catch {
    }
  }

  private async ensureWorkOrdersSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    await this.ensureOrderNumber(conn);
    await this.ensureColumn(
      conn,
      'verification_code',
      "ALTER TABLE work_orders ADD COLUMN verification_code VARCHAR(20) NULL AFTER order_number",
    );
    await this.ensureColumn(
      conn,
      'device_password',
      "ALTER TABLE work_orders ADD COLUMN device_password VARCHAR(255) NULL AFTER device_model",
    );
    await this.ensureColumn(
      conn,
      'priority',
      "ALTER TABLE work_orders ADD COLUMN priority VARCHAR(32) NOT NULL DEFAULT 'medium' AFTER status",
    );
    await this.ensureColumn(conn, 'estimated_cost', 'ALTER TABLE work_orders ADD COLUMN estimated_cost DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER priority');
    await this.ensureColumn(conn, 'final_cost', 'ALTER TABLE work_orders ADD COLUMN final_cost DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER estimated_cost');
    await this.ensureColumn(conn, 'advance_payment', 'ALTER TABLE work_orders ADD COLUMN advance_payment DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER final_cost');
    await this.ensureColumn(conn, 'payment_method', "ALTER TABLE work_orders ADD COLUMN payment_method VARCHAR(64) NULL AFTER advance_payment");
    await this.ensureColumn(conn, 'payment_reference', 'ALTER TABLE work_orders ADD COLUMN payment_reference VARCHAR(128) NULL AFTER payment_method');
    await this.ensureColumn(conn, 'technician_notes', 'ALTER TABLE work_orders ADD COLUMN technician_notes TEXT NULL AFTER payment_method');
    await this.ensureColumn(conn, 'client_observations', 'ALTER TABLE work_orders ADD COLUMN client_observations TEXT NULL AFTER reported_issue');
    await this.ensureColumn(conn, 'diagnosis', 'ALTER TABLE work_orders ADD COLUMN diagnosis TEXT NULL AFTER technician_notes');
    await this.ensureColumn(conn, 'solution', 'ALTER TABLE work_orders ADD COLUMN solution TEXT NULL AFTER diagnosis');
    await this.ensureColumn(conn, 'estimated_completion', 'ALTER TABLE work_orders ADD COLUMN estimated_completion DATE NULL AFTER solution');
    await this.ensureColumn(conn, 'completed_date', 'ALTER TABLE work_orders ADD COLUMN completed_date DATETIME NULL AFTER estimated_completion');
    await this.ensureColumn(conn, 'delivered_date', 'ALTER TABLE work_orders ADD COLUMN delivered_date DATETIME NULL AFTER completed_date');
    await this.ensureColumn(
      conn,
      'approval_status',
      "ALTER TABLE work_orders ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'none' AFTER status",
    );
    await this.ensureColumn(conn, 'approved_at', 'ALTER TABLE work_orders ADD COLUMN approved_at DATETIME NULL AFTER approval_status');
    await this.ensureColumn(
      conn,
      'approved_quote_amount',
      'ALTER TABLE work_orders ADD COLUMN approved_quote_amount DECIMAL(10,2) NULL AFTER approved_at',
    );
    await this.ensureColumn(
      conn,
      'approval_comment',
      'ALTER TABLE work_orders ADD COLUMN approval_comment TEXT NULL AFTER approved_quote_amount',
    );
    await this.ensureColumn(
      conn,
      'approval_signature',
      'ALTER TABLE work_orders ADD COLUMN approval_signature TEXT NULL AFTER approval_comment',
    );
  }

  private async ensureOrderStatusHistory(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS order_status_history (
          id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
          order_id INT NOT NULL,
          status VARCHAR(64) NOT NULL,
          user_id INT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_order_id (order_id)
        )
        `,
      );
    } catch {
    }
  }

  private async ensureTechnicalReports(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS technical_reports (
          id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
          order_id INT NOT NULL,
          report_title VARCHAR(255) NOT NULL,
          diagnosis TEXT NULL,
          procedure_taken TEXT NULL,
          introduction TEXT NULL,
          conclusion TEXT NULL,
          photos_json LONGTEXT NULL,
          created_by INT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_order_id (order_id),
          KEY idx_created_at (created_at)
        )
        `,
      );
    } catch {
    }
  }

  private async ensureWorkOrderServices(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
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
          KEY idx_order_id (work_order_id),
          KEY idx_service_id (service_id),
          KEY idx_created_at (created_at)
        )
        `,
      );
    } catch {
    }
  }

  private async ensureOrderStatuses(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS order_statuses (
          id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
          slug VARCHAR(64) NOT NULL,
          name VARCHAR(128) NOT NULL,
          emoji VARCHAR(32) NULL,
          color VARCHAR(16) NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          sort_order INT NOT NULL DEFAULT 0,
          UNIQUE KEY uniq_slug (slug)
        )
        `,
      );
    } catch {
      return;
    }

    try {
      const [rows] = await conn.query<RowDataPacket[]>(
        'SELECT COUNT(*) as c FROM order_statuses WHERE is_active = 1',
      );
      const c = Number(rows?.[0]?.c ?? 0);
      if (c > 0) return;
    } catch {
      return;
    }

    const defaults: Array<{ slug: string; name: string; emoji: string; color: string; sort: number }> = [
      { slug: 'pending', name: 'Pendiente', emoji: '⏳', color: '#ffc107', sort: 1 },
      { slug: 'esperando_aprobacion', name: 'Esperando Aprobación', emoji: '✍️', color: '#ffc107', sort: 2 },
      { slug: 'received', name: 'Asignado', emoji: '📦', color: '#6cc4ea', sort: 3 },
      { slug: 'diagnosing', name: 'Diagnosticando', emoji: '🔍', color: '#fd7e14', sort: 4 },
      { slug: 'waiting_parts', name: 'Esperando Repuestos', emoji: '⏸️', color: '#6f42c1', sort: 5 },
      { slug: 'repairing', name: 'Reparando', emoji: '🔧', color: '#007bff', sort: 6 },
      { slug: 'testing', name: 'Testeando', emoji: '🧪', color: '#17a2b8', sort: 7 },
      { slug: 'completed', name: 'Completado', emoji: '✅', color: '#28a745', sort: 8 },
      { slug: 'delivered', name: 'Entregado', emoji: '🚚', color: '#6c757d', sort: 9 },
      { slug: 'cancelled', name: 'Cancelado', emoji: '❌', color: '#dc3545', sort: 10 },
    ];

    for (const d of defaults) {
      try {
        await conn.execute(
          `
          INSERT INTO order_statuses (slug, name, emoji, color, is_active, sort_order)
          VALUES (?, ?, ?, ?, 1, ?)
          ON DUPLICATE KEY UPDATE name = VALUES(name), emoji = VALUES(emoji), color = VALUES(color), sort_order = VALUES(sort_order)
          `,
          [d.slug, d.name, d.emoji, d.color, d.sort],
        );
      } catch {
      }
    }
  }

  async listStatuses(input: { empresaId: number }): Promise<OrderStatusRow[]> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureOrderStatuses(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT slug, name, COALESCE(emoji,'') as emoji, COALESCE(color,'') as color, sort_order
        FROM order_statuses
        WHERE is_active = 1
        ORDER BY sort_order, name
        `,
      );
      return (rows ?? []).map((r) => ({
        slug: String(r.slug ?? ''),
        name: String(r.name ?? ''),
        emoji: String(r.emoji ?? ''),
        color: String(r.color ?? ''),
        sortOrder: Number(r.sort_order ?? 0),
      }));
    } catch {
      return [];
    } finally {
      await conn.end();
    }
  }

  async serialLookup(input: { empresaId: number; serial: string }): Promise<SerialLookupRow | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureWorkOrdersSchema(conn);
      const raw = input.serial.trim();
      if (!raw) return null;
      const normalized = raw.replace(/[\s\-_]/g, '').toLowerCase();
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, client_id, device_type_id, COALESCE(device_brand,'') as device_brand, COALESCE(device_model,'') as device_model
        FROM work_orders
        WHERE LOWER(REPLACE(REPLACE(REPLACE(serial_number,' ',''),'-',''),'_','')) = ?
        ORDER BY id DESC
        LIMIT 1
        `,
        [normalized],
      );
      const r = rows?.[0];
      if (!r) return null;
      return {
        orderId: Number(r.id),
        clientId: Number(r.client_id ?? 0),
        deviceTypeId: Number(r.device_type_id ?? 0),
        deviceBrand: String(r.device_brand ?? ''),
        deviceModel: String(r.device_model ?? ''),
      };
    } catch {
      return null;
    } finally {
      await conn.end();
    }
  }

  async list(input: {
    empresaId: number;
    search: string;
    status: string;
    approvalStatus: string;
    clientId: number | null;
    limit: number;
    offset: number;
  }): Promise<{ rows: OrderListRow[]; total: number }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureWorkOrdersSchema(conn);
      const search = input.search.trim();
      const whereRich: string[] = [];
      const whereBare: string[] = [];
      const whereBareMin: string[] = [];
      const paramsRich: Array<string | number> = [];
      const paramsBare: Array<string | number> = [];
      const paramsBareMin: Array<string | number> = [];

      const clientId = input.clientId && Number.isFinite(input.clientId) ? Number(input.clientId) : null;
      if (clientId && clientId > 0) {
        whereRich.push('o.client_id = ?');
        whereBare.push('o.client_id = ?');
        whereBareMin.push('o.client_id = ?');
        paramsRich.push(clientId);
        paramsBare.push(clientId);
        paramsBareMin.push(clientId);
      }

      if (input.status.trim()) {
        const canonical = canonicalStatus(input.status.trim());
        const matches = new Set<string>([canonical]);
        for (const [raw, canon] of Object.entries(STATUS_SYNONYMS)) {
          if (canon === canonical) matches.add(raw);
        }
        const values = [...matches].map((v) => v.trim().toLowerCase()).filter(Boolean);
        const placeholders = values.map(() => '?').join(', ');
        whereRich.push(`LOWER(TRIM(o.status)) IN (${placeholders})`);
        whereBare.push(`LOWER(TRIM(o.status)) IN (${placeholders})`);
        whereBareMin.push(`LOWER(TRIM(o.status)) IN (${placeholders})`);
        paramsRich.push(...values);
        paramsBare.push(...values);
        paramsBareMin.push(...values);
      }

      if (input.approvalStatus.trim()) {
        whereRich.push('o.approval_status = ?');
        whereBare.push('o.approval_status = ?');
        whereBareMin.push('o.approval_status = ?');
        paramsRich.push(input.approvalStatus.trim());
        paramsBare.push(input.approvalStatus.trim());
        paramsBareMin.push(input.approvalStatus.trim());
      }

      if (search) {
        const sp = `%${search}%`;
        whereRich.push(
          '(CAST(o.id AS CHAR) LIKE ? OR o.order_number LIKE ? OR o.device_brand LIKE ? OR o.device_model LIKE ? OR o.serial_number LIKE ? OR c.first_name LIKE ? OR c.company_name LIKE ?)',
        );
        paramsRich.push(sp, sp, sp, sp, sp, sp, sp);

        whereBare.push(
          '(CAST(o.id AS CHAR) LIKE ? OR o.order_number LIKE ? OR o.device_brand LIKE ? OR o.device_model LIKE ? OR o.serial_number LIKE ?)',
        );
        paramsBare.push(sp, sp, sp, sp, sp);

        whereBareMin.push('(CAST(o.id AS CHAR) LIKE ? OR o.device_brand LIKE ? OR o.device_model LIKE ? OR o.serial_number LIKE ?)');
        paramsBareMin.push(sp, sp, sp, sp);
      }

      const whereSqlRich = whereRich.length ? `WHERE ${whereRich.join(' AND ')}` : '';
      const whereSqlBare = whereBare.length ? `WHERE ${whereBare.join(' AND ')}` : '';
      const whereSqlBareMin = whereBareMin.length ? `WHERE ${whereBareMin.join(' AND ')}` : '';

      let total = 0;
      try {
        const [countRows] = await conn.query<RowDataPacket[]>(
          `
          SELECT COUNT(*) as total
          FROM work_orders o
          LEFT JOIN clients c ON o.client_id = c.id
          ${whereSqlRich}
          `,
          paramsRich,
        );
        total = Number(countRows?.[0]?.total ?? 0);
      } catch {
        try {
          const [countRowsBare] = await conn.query<RowDataPacket[]>(
            `
            SELECT COUNT(*) as total
            FROM work_orders o
            ${whereSqlBare}
            `,
            paramsBare,
          );
          total = Number(countRowsBare?.[0]?.total ?? 0);
        } catch {
          try {
            const [countRowsBareMin] = await conn.query<RowDataPacket[]>(
              `
              SELECT COUNT(*) as total
              FROM work_orders o
              ${whereSqlBareMin}
              `,
              paramsBareMin,
            );
            total = Number(countRowsBareMin?.[0]?.total ?? 0);
          } catch {
            total = 0;
          }
        }
      }

      let rows: RowDataPacket[] = [];
      try {
        const [r] = await conn.query<RowDataPacket[]>(
          `
          SELECT 
            o.id,
            COALESCE(o.order_number, '') as order_number,
            COALESCE(o.reported_issue, '') as reported_issue,
            o.device_brand,
            o.device_model,
            o.serial_number,
            o.status,
            COALESCE(o.approval_status, 'none') as approval_status,
            o.priority,
            o.created_at,
            COALESCE(c.first_name, '') as first_name,
            COALESCE(c.company_name, '') as company_name,
            COALESCE(c.phone, '') as client_phone,
            COALESCE(c.email, '') as client_email,
            COALESCE(dt.name, '') as device_type_name
          FROM work_orders o
          LEFT JOIN clients c ON o.client_id = c.id
          LEFT JOIN device_types dt ON o.device_type_id = dt.id
          ${whereSqlRich}
          ORDER BY o.created_at DESC
          LIMIT ? OFFSET ?
          `,
          [...paramsRich, input.limit, input.offset],
        );
        rows = r ?? [];
      } catch {
        try {
          const [r2] = await conn.query<RowDataPacket[]>(
            `
            SELECT 
              o.id,
              COALESCE(o.order_number, '') as order_number,
              COALESCE(o.reported_issue, '') as reported_issue,
              o.device_brand,
              o.device_model,
              o.serial_number,
              o.status,
              COALESCE(o.approval_status, 'none') as approval_status,
              o.priority,
              o.created_at,
              COALESCE(c.first_name, '') as first_name,
              COALESCE(c.company_name, '') as company_name
            FROM work_orders o
            LEFT JOIN clients c ON o.client_id = c.id
            ${whereSqlRich}
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
            `,
            [...paramsRich, input.limit, input.offset],
          );
          rows = r2 ?? [];
        } catch {
          try {
            const [r3] = await conn.query<RowDataPacket[]>(
              `
              SELECT 
                o.id,
                COALESCE(o.order_number, '') as order_number,
                COALESCE(o.reported_issue, '') as reported_issue,
                o.device_brand,
                o.device_model,
                o.serial_number,
                o.status,
                COALESCE(o.approval_status, 'none') as approval_status,
                o.priority,
                o.created_at
              FROM work_orders o
              ${whereSqlBare}
              ORDER BY o.created_at DESC
              LIMIT ? OFFSET ?
              `,
              [...paramsBare, input.limit, input.offset],
            );
            rows = r3 ?? [];
          } catch {
            try {
              const [r4] = await conn.query<RowDataPacket[]>(
                `
                SELECT
                  o.id,
                  '' as order_number,
                  '' as reported_issue,
                  COALESCE(o.device_brand, '') as device_brand,
                  COALESCE(o.device_model, '') as device_model,
                  COALESCE(o.serial_number, '') as serial_number,
                  COALESCE(o.status, '') as status,
                  'none' as approval_status,
                  '' as priority,
                  COALESCE(o.created_at, '') as created_at
                FROM work_orders o
                ${whereSqlBareMin}
                ORDER BY o.id DESC
                LIMIT ? OFFSET ?
                `,
                [...paramsBareMin, input.limit, input.offset],
              );
              rows = r4 ?? [];
            } catch {
              rows = [];
            }
          }
        }
      }

      return {
        total,
        rows: (rows ?? []).map((r) => ({
          id: Number(r.id),
          orderNumber: String(r.order_number ?? ''),
          clientName:
            String(r.company_name ?? '').trim() ||
            String(r.first_name ?? '').trim() ||
            `Cliente #${Number(r.id ?? 0)}`,
          clientPhone: String(r.client_phone ?? ''),
          clientEmail: String(r.client_email ?? ''),
          deviceTypeName: String(r.device_type_name ?? ''),
          deviceBrand: String(r.device_brand ?? ''),
          deviceModel: String(r.device_model ?? ''),
          serialNumber: String(r.serial_number ?? ''),
          reportedIssue: String(r.reported_issue ?? ''),
          status: String(r.status ?? ''),
          approvalStatus: String(r.approval_status ?? 'none'),
          priority: String(r.priority ?? ''),
          createdAt: String(r.created_at ?? ''),
        })),
      };
    } finally {
      await conn.end();
    }
  }

  async statsByClientId(input: {
    empresaId: number;
    clientId: number;
  }): Promise<{ total: number; pending: number; inProcess: number; completed: number }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureWorkOrdersSchema(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT COALESCE(status, '') as status
        FROM work_orders
        WHERE client_id = ?
        `,
        [input.clientId],
      );
      const pendingSet = new Set(['pending', 'received', 'esperando_aprobacion']);
      const completedSet = new Set(['completed', 'delivered']);
      const cancelledSet = new Set(['cancelled']);
      let pending = 0;
      let completed = 0;
      let inProcess = 0;
      const total = (rows ?? []).length;
      for (const r of rows ?? []) {
        const st = canonicalStatus(String(r.status ?? ''));
        if (pendingSet.has(st)) pending++;
        else if (completedSet.has(st)) completed++;
        else if (cancelledSet.has(st)) {
        } else inProcess++;
      }
      return { total, pending, inProcess, completed };
    } finally {
      await conn.end();
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<OrderRow | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureWorkOrdersSchema(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT 
          o.id,
          COALESCE(o.order_number, '') as order_number,
          o.client_id,
          o.device_type_id,
          o.device_brand,
          o.device_model,
          COALESCE(o.device_password, '') as device_password,
          o.serial_number,
          COALESCE(o.reported_issue, '') as reported_issue,
          COALESCE(o.client_observations, '') as client_observations,
          COALESCE(o.status, '') as status,
          COALESCE(o.approval_status, 'none') as approval_status,
          o.approved_at,
          o.approved_quote_amount,
          o.approval_comment,
          o.approval_signature,
          COALESCE(o.priority, '') as priority,
          COALESCE(o.estimated_cost, 0) as estimated_cost,
          COALESCE(o.final_cost, 0) as final_cost,
          COALESCE(o.advance_payment, 0) as advance_payment,
          COALESCE(o.payment_method, '') as payment_method,
          COALESCE(o.payment_reference, '') as payment_reference,
          COALESCE(o.technician_notes, '') as technician_notes,
          COALESCE(o.diagnosis, '') as diagnosis,
          COALESCE(o.solution, '') as solution,
          o.estimated_completion,
          o.created_at,
          COALESCE(o.updated_at, '') as updated_at,
          COALESCE(c.first_name, '') as first_name,
          COALESCE(c.company_name, '') as company_name,
          COALESCE(dt.name, '') as device_type_name
        FROM work_orders o
        LEFT JOIN clients c ON o.client_id = c.id
        LEFT JOIN device_types dt ON o.device_type_id = dt.id
        WHERE o.id = ?
        LIMIT 1
        `,
        [input.id],
      );
      const r = rows?.[0];
      if (!r) return null;
      const clientName = String(r.company_name ?? '').trim() || String(r.first_name ?? '').trim();
      let accessoryIds: number[] = [];
      try {
        const [aRows] = await conn.query<RowDataPacket[]>(
          `
          SELECT accessory_id
          FROM order_equipment_accessories
          WHERE order_id = ? AND is_included = 1
          `,
          [input.id],
        );
        accessoryIds = (aRows ?? [])
          .map((x) => Number(x.accessory_id ?? 0))
          .filter((v) => Number.isFinite(v) && v > 0);
      } catch {
        accessoryIds = [];
      }
      return {
        id: Number(r.id),
        orderNumber: String(r.order_number ?? ''),
        clientId: Number(r.client_id ?? 0),
        clientName,
        deviceTypeId: Number(r.device_type_id ?? 0),
        deviceTypeName: String(r.device_type_name ?? ''),
        deviceBrand: String(r.device_brand ?? ''),
        deviceModel: String(r.device_model ?? ''),
        devicePassword: String(r.device_password ?? ''),
        serialNumber: String(r.serial_number ?? ''),
        reportedIssue: String(r.reported_issue ?? ''),
        clientObservations: String(r.client_observations ?? ''),
        status: String(r.status ?? ''),
        approvalStatus: String(r.approval_status ?? 'none'),
        approvedAt: r.approved_at ? String(r.approved_at) : null,
        approvedQuoteAmount:
          r.approved_quote_amount === undefined || r.approved_quote_amount === null
            ? null
            : Number(r.approved_quote_amount),
        approvalComment: r.approval_comment === undefined || r.approval_comment === null ? null : String(r.approval_comment),
        approvalSignature:
          r.approval_signature === undefined || r.approval_signature === null ? null : String(r.approval_signature),
        priority: String(r.priority ?? ''),
        estimatedCost: Number(r.estimated_cost ?? 0),
        finalCost: Number(r.final_cost ?? 0),
        advancePayment: Number(r.advance_payment ?? 0),
        paymentMethod: String(r.payment_method ?? ''),
        paymentReference: String(r.payment_reference ?? ''),
        technicianNotes: String(r.technician_notes ?? ''),
        diagnosis: String(r.diagnosis ?? ''),
        solution: String(r.solution ?? ''),
        estimatedCompletion: r.estimated_completion === undefined || r.estimated_completion === null ? null : String(r.estimated_completion),
        accessoryIds,
        createdAt: String(r.created_at ?? ''),
        updatedAt: String(r.updated_at ?? ''),
      };
    } finally {
      await conn.end();
    }
  }

  async create(input: {
    empresaId: number;
    clientId: number;
    deviceTypeId: number;
    deviceBrand: string;
    deviceModel: string;
    devicePassword: string;
    serialNumber: string;
    reportedIssue: string;
    clientObservations: string;
    status: string;
    priority: string;
    estimatedCost: number;
    advancePayment: number;
    paymentMethod: string;
    paymentReference: string;
    technicianNotes: string;
    estimatedCompletion: string | null;
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureWorkOrdersSchema(conn);
      const [result] = await conn.execute(
        `
        INSERT INTO work_orders (
          client_id, device_type_id, device_brand, device_model, device_password, serial_number,
          reported_issue, client_observations, status, priority, estimated_cost, advance_payment, payment_method, payment_reference,
          technician_notes, estimated_completion, created_at, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        `,
        [
          input.clientId,
          input.deviceTypeId,
          input.deviceBrand,
          input.deviceModel,
          input.devicePassword,
          input.serialNumber,
          input.reportedIssue,
          input.clientObservations,
          input.status,
          input.priority,
          input.estimatedCost,
          input.advancePayment,
          input.paymentMethod,
          input.paymentReference,
          input.technicianNotes,
          input.estimatedCompletion,
        ],
      );
      const anyResult = result as { insertId?: number };
      const id = Number(anyResult.insertId ?? 0);

      try {
        const orderNumber = `WO-${id}`;
        await conn.execute('UPDATE work_orders SET order_number = ? WHERE id = ? AND (order_number IS NULL OR order_number = "")', [
          orderNumber,
          id,
        ]);
      } catch {
      }

      return id;
    } finally {
      await conn.end();
    }
  }

  async update(input: {
    empresaId: number;
    id: number;
    clientId: number;
    deviceTypeId: number;
    deviceBrand: string;
    deviceModel: string;
    devicePassword: string;
    serialNumber: string;
    reportedIssue: string;
    clientObservations: string;
    priority: string;
    estimatedCost: number;
    finalCost: number | null;
    advancePayment: number;
    paymentMethod: string;
    paymentReference: string;
    technicianNotes: string;
    diagnosis: string;
    solution: string;
    estimatedCompletion: string | null;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureWorkOrdersSchema(conn);
      const [result] = await conn.execute(
        `
        UPDATE work_orders
        SET client_id = ?, device_type_id = ?, device_brand = ?, device_model = ?, device_password = ?,
            serial_number = ?, reported_issue = ?, client_observations = ?, priority = ?, estimated_cost = ?, final_cost = COALESCE(?, final_cost), advance_payment = ?,
            payment_method = ?, payment_reference = ?, technician_notes = ?, diagnosis = ?, solution = ?, estimated_completion = ?,
            updated_at = NOW()
        WHERE id = ?
        `,
        [
          input.clientId,
          input.deviceTypeId,
          input.deviceBrand,
          input.deviceModel,
          input.devicePassword,
          input.serialNumber,
          input.reportedIssue,
          input.clientObservations,
          input.priority,
          input.estimatedCost,
          input.finalCost,
          input.advancePayment,
          input.paymentMethod,
          input.paymentReference,
          input.technicianNotes,
          input.diagnosis,
          input.solution,
          input.estimatedCompletion,
          input.id,
        ],
      );
      const anyResult = result as { affectedRows?: number };
      return Number(anyResult.affectedRows ?? 0) > 0;
    } finally {
      await conn.end();
    }
  }

  async setStatus(input: {
    empresaId: number;
    id: number;
    status: string;
    userId: number | null;
    finalCost?: number | null;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureWorkOrdersSchema(conn);
      await this.ensureOrderStatusHistory(conn);
      await this.ensureOrderStatuses(conn);
      const st = canonicalStatus(input.status);

      try {
        const [rows] = await conn.query<RowDataPacket[]>(
          'SELECT slug FROM order_statuses WHERE is_active = 1 AND slug = ? LIMIT 1',
          [st],
        );
        if (!rows?.[0] && st !== 'esperando_aprobacion') {
          return false;
        }
      } catch {
      }

      let completedDate = null as string | null;
      let deliveredDate = null as string | null;
      if (st === 'completed') completedDate = new Date().toISOString().slice(0, 19).replace('T', ' ');
      if (st === 'delivered') deliveredDate = new Date().toISOString().slice(0, 19).replace('T', ' ');

      const finalCost =
        input.finalCost === undefined || input.finalCost === null ? null : Number(input.finalCost ?? 0);

      const isWaitingApproval = st === 'esperando_aprobacion';
      const nextStatus = isWaitingApproval ? 'pending' : st;
      const approvalStatus = isWaitingApproval ? 'pending' : null;

      const [result] = await conn.execute(
        `
        UPDATE work_orders
        SET status = ?,
            approval_status = COALESCE(?, approval_status),
            completed_date = COALESCE(completed_date, ?),
            delivered_date = COALESCE(delivered_date, ?),
            final_cost = COALESCE(?, final_cost),
            updated_at = NOW()
        WHERE id = ?
        `,
        [nextStatus, approvalStatus, completedDate, deliveredDate, finalCost, input.id],
      );
      const anyResult = result as { affectedRows?: number };
      const ok = Number(anyResult.affectedRows ?? 0) > 0;
      if (!ok) return false;
      try {
        await conn.execute(
          'INSERT INTO order_status_history (order_id, status, user_id, created_at) VALUES (?, ?, ?, NOW())',
          [input.id, nextStatus, input.userId],
        );
      } catch {
      }
      return true;
    } finally {
      await conn.end();
    }
  }

  async setApproval(input: {
    empresaId: number;
    id: number;
    approvalStatus: 'none' | 'pending' | 'approved' | 'rejected';
    approvedQuoteAmount?: number | null;
    approvalComment?: string | null;
    approvalSignature?: string | null;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureWorkOrdersSchema(conn);
      const approvedAt = input.approvalStatus === 'approved' ? new Date().toISOString().slice(0, 19).replace('T', ' ') : null;
      const [result] = await conn.execute(
        `
        UPDATE work_orders
        SET approval_status = ?,
            approved_at = ?,
            approved_quote_amount = ?,
            approval_comment = ?,
            approval_signature = ?,
            updated_at = NOW()
        WHERE id = ?
        `,
        [
          input.approvalStatus,
          approvedAt,
          input.approvedQuoteAmount ?? null,
          input.approvalComment ?? null,
          input.approvalSignature ?? null,
          input.id,
        ],
      );
      const anyResult = result as { affectedRows?: number };
      return Number(anyResult.affectedRows ?? 0) > 0;
    } finally {
      await conn.end();
    }
  }

  async history(input: { empresaId: number; id: number }): Promise<OrderStatusHistoryRow[]> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureOrderStatusHistory(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, status, user_id, created_at
        FROM order_status_history
        WHERE order_id = ?
        ORDER BY id DESC
        LIMIT 200
        `,
        [input.id],
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        status: String(r.status ?? ''),
        userId: r.user_id === undefined || r.user_id === null ? null : Number(r.user_id),
        createdAt: String(r.created_at ?? ''),
      }));
    } catch {
      return [];
    } finally {
      await conn.end();
    }
  }

  async delete(input: { empresaId: number; id: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      const [result] = await conn.execute('DELETE FROM work_orders WHERE id = ? LIMIT 1', [input.id]);
      const anyResult = result as { affectedRows?: number };
      return Number(anyResult.affectedRows ?? 0) > 0;
    } finally {
      await conn.end();
    }
  }

  async listTechnicalReports(input: { empresaId: number; orderId: number }): Promise<TechnicalReportListRow[]> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureTechnicalReports(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, report_title, created_by, created_at
        FROM technical_reports
        WHERE order_id = ?
        ORDER BY id DESC
        LIMIT 200
        `,
        [input.orderId],
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        reportTitle: String(r.report_title ?? ''),
        createdAt: String(r.created_at ?? ''),
        createdBy: r.created_by === undefined || r.created_by === null ? null : Number(r.created_by),
      }));
    } catch {
      return [];
    } finally {
      await conn.end();
    }
  }

  async getTechnicalReport(input: { empresaId: number; orderId: number; reportId: number }): Promise<TechnicalReportRow | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureTechnicalReports(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, order_id, report_title, diagnosis, procedure_taken, introduction, conclusion, photos_json, created_by, created_at
        FROM technical_reports
        WHERE order_id = ? AND id = ?
        LIMIT 1
        `,
        [input.orderId, input.reportId],
      );
      const r = rows?.[0];
      if (!r) return null;
      return {
        id: Number(r.id),
        orderId: Number(r.order_id ?? 0),
        reportTitle: String(r.report_title ?? ''),
        diagnosis: String(r.diagnosis ?? ''),
        procedureTaken: String(r.procedure_taken ?? ''),
        introduction: String(r.introduction ?? ''),
        conclusion: String(r.conclusion ?? ''),
        photosJson: r.photos_json === undefined || r.photos_json === null ? null : String(r.photos_json),
        createdAt: String(r.created_at ?? ''),
        createdBy: r.created_by === undefined || r.created_by === null ? null : Number(r.created_by),
      };
    } catch {
      return null;
    } finally {
      await conn.end();
    }
  }

  async createTechnicalReport(input: {
    empresaId: number;
    orderId: number;
    reportTitle: string;
    diagnosis: string;
    procedureTaken: string;
    introduction: string;
    conclusion: string;
    photosJson: string | null;
    createdBy: number | null;
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureTechnicalReports(conn);
      const [result] = await conn.execute(
        `
        INSERT INTO technical_reports (order_id, report_title, diagnosis, procedure_taken, introduction, conclusion, photos_json, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        `,
        [
          input.orderId,
          input.reportTitle,
          input.diagnosis,
          input.procedureTaken,
          input.introduction,
          input.conclusion,
          input.photosJson,
          input.createdBy,
        ],
      );
      const anyResult = result as unknown as { insertId?: number };
      return Number(anyResult.insertId ?? 0);
    } finally {
      await conn.end();
    }
  }

  async deleteTechnicalReport(input: { empresaId: number; orderId: number; reportId: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureTechnicalReports(conn);
      const [result] = await conn.execute('DELETE FROM technical_reports WHERE order_id = ? AND id = ? LIMIT 1', [
        input.orderId,
        input.reportId,
      ]);
      const anyResult = result as unknown as { affectedRows?: number };
      return Number(anyResult.affectedRows ?? 0) > 0;
    } finally {
      await conn.end();
    }
  }

  async listOrderServices(input: { empresaId: number; orderId: number }): Promise<WorkOrderServiceRow[]> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureWorkOrderServices(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT
          wos.id, wos.work_order_id, wos.service_id, wos.quantity, wos.service_price, wos.total_price, wos.created_at,
          COALESCE(s.name, '') as service_name
        FROM work_order_services wos
        LEFT JOIN services s ON s.id = wos.service_id
        WHERE wos.work_order_id = ?
        ORDER BY wos.id DESC
        LIMIT 200
        `,
        [input.orderId],
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        workOrderId: Number(r.work_order_id ?? 0),
        serviceId: Number(r.service_id ?? 0),
        serviceName: String(r.service_name ?? ''),
        quantity: Number(r.quantity ?? 0),
        servicePrice: Number(r.service_price ?? 0),
        totalPrice: Number(r.total_price ?? 0),
        createdAt: String(r.created_at ?? ''),
      }));
    } catch {
      return [];
    } finally {
      await conn.end();
    }
  }

  async addOrderService(input: {
    empresaId: number;
    orderId: number;
    serviceId: number;
    quantity: number;
    servicePrice: number;
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureWorkOrderServices(conn);
      const qty = Number.isFinite(input.quantity) && input.quantity > 0 ? Math.round(input.quantity * 100) / 100 : 1;
      const price = Number.isFinite(input.servicePrice) && input.servicePrice >= 0 ? Math.round(input.servicePrice * 100) / 100 : 0;
      const total = Math.round(qty * price * 100) / 100;
      const [r] = await conn.execute(
        `
        INSERT INTO work_order_services (work_order_id, service_id, quantity, service_price, total_price, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        `,
        [input.orderId, input.serviceId, qty, price, total],
      );
      return Number((r as unknown as { insertId?: number })?.insertId ?? 0);
    } finally {
      await conn.end();
    }
  }

  async deleteOrderService(input: { empresaId: number; orderId: number; itemId: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureWorkOrderServices(conn);
      const [r] = await conn.execute('DELETE FROM work_order_services WHERE work_order_id = ? AND id = ? LIMIT 1', [
        input.orderId,
        input.itemId,
      ]);
      return Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0) > 0;
    } finally {
      await conn.end();
    }
  }
}
