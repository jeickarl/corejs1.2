import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

function asBool(v: string): boolean {
  const t = (v ?? '').trim().toLowerCase();
  return t === '1' || t === 'true' || t === 'yes' || t === 'y';
}

function normalizeAlphaNum(v: string): string {
  return (v ?? '')
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, '')
    .trim();
}

function makeVerificationCode(len = 6): string {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  let out = '';
  for (let i = 0; i < len; i++) {
    out += alphabet[Math.floor(Math.random() * alphabet.length)];
  }
  return out;
}

export type PortalConfig = {
  enableLookupById: boolean;
  showTimeline: boolean;
  allowApproval: boolean;
  homeTitle: string;
  homeSubtitle: string;
  whatsappLink: string;
  addressText: string;
  hoursText: string;
  mapEmbedUrl: string;
};

export type PortalOrder = {
  id: number;
  orderNumber: string;
  clientId: number;
  clientName: string;
  deviceBrand: string;
  deviceModel: string;
  reportedIssue: string;
  status: string;
  approvalStatus: string;
  estimatedCost: number;
  verificationCode: string;
  createdAt: string;
};

export type PortalHistoryItem = {
  id: number;
  status: string;
  createdAt: string;
};

@Injectable()
export class PortalDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureNotificationsSchema() {
    try {
      await this.masterPool.query('SELECT id FROM notifications LIMIT 1');
    } catch {
      try {
        await this.masterPool.query(
          `
          CREATE TABLE IF NOT EXISTS notifications (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NULL,
            type VARCHAR(32) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_empresa_id (empresa_id),
            KEY idx_created_at (created_at)
          )
          `,
        );
      } catch {
      }
    }

    try {
      await this.masterPool.query('SELECT id FROM user_notifications LIMIT 1');
    } catch {
      try {
        await this.masterPool.query(
          `
          CREATE TABLE IF NOT EXISTS user_notifications (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            user_id INT NOT NULL,
            notification_id INT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_empresa_user (empresa_id, user_id),
            KEY idx_user_read (user_id, is_read),
            KEY idx_notification_id (notification_id)
          )
          `,
        );
      } catch {
      }
    }
  }

  private async ensureSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query('SELECT config_key FROM system_config LIMIT 1');
    } catch {
      try {
        await conn.query(
          `
          CREATE TABLE IF NOT EXISTS system_config (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            config_key VARCHAR(255) NOT NULL,
            config_value TEXT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_config_key (config_key)
          )
          `,
        );
      } catch {
      }
    }

    try {
      await conn.query('SELECT verification_code FROM work_orders LIMIT 1');
    } catch {
      try {
        await conn.query("ALTER TABLE work_orders ADD COLUMN verification_code VARCHAR(20) NULL AFTER order_number");
      } catch {
      }
    }

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

  private async getConfig(conn: Awaited<ReturnType<typeof createTenantConnection>>, key: string): Promise<string> {
    try {
      const [rows] = await conn.query<RowDataPacket[]>(
        'SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1',
        [key],
      );
      return String(rows?.[0]?.config_value ?? '');
    } catch {
      return '';
    }
  }

  async getPortalConfig(input: { empresaId: number }): Promise<PortalConfig> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const enableLookupById = await this.getConfig(conn, 'client_portal_enable_lookup_by_id');
      const showTimeline = await this.getConfig(conn, 'client_portal_show_timeline');
      const allowApproval = await this.getConfig(conn, 'client_portal_allow_approval');
      const homeTitle = await this.getConfig(conn, 'client_portal_home_title');
      const homeSubtitle = await this.getConfig(conn, 'client_portal_home_subtitle');
      const whatsappLink = await this.getConfig(conn, 'client_portal_whatsapp_link');
      const addressText = await this.getConfig(conn, 'client_portal_address_text');
      const hoursText = await this.getConfig(conn, 'client_portal_hours_text');
      const mapEmbedUrl = await this.getConfig(conn, 'client_portal_map_embed_url');
      return {
        enableLookupById: asBool(enableLookupById),
        showTimeline: asBool(showTimeline),
        allowApproval: asBool(allowApproval),
        homeTitle: homeTitle || '',
        homeSubtitle: homeSubtitle || '',
        whatsappLink: whatsappLink || '',
        addressText: addressText || '',
        hoursText: hoursText || '',
        mapEmbedUrl: mapEmbedUrl || '',
      };
    } finally {
      await conn.end();
    }
  }

  private mapOrderRow(r: RowDataPacket): PortalOrder {
    return {
      id: Number(r.id),
      orderNumber: String(r.order_number ?? ''),
      clientId: Number(r.client_id ?? 0),
      clientName: String(r.client_name ?? ''),
      deviceBrand: String(r.device_brand ?? ''),
      deviceModel: String(r.device_model ?? ''),
      reportedIssue: String(r.reported_issue ?? ''),
      status: String(r.status ?? ''),
      approvalStatus: String(r.approval_status ?? 'none'),
      estimatedCost: Number(r.estimated_cost ?? 0),
      verificationCode: String(r.verification_code ?? ''),
      createdAt: String(r.created_at ?? ''),
    };
  }

  private async ensureVerificationCode(conn: Awaited<ReturnType<typeof createTenantConnection>>, orderId: number): Promise<string> {
    const [rows] = await conn.query<RowDataPacket[]>(
      'SELECT verification_code FROM work_orders WHERE id = ? LIMIT 1',
      [orderId],
    );
    const existing = String(rows?.[0]?.verification_code ?? '').trim();
    if (existing) return existing;
    const code = makeVerificationCode(6);
    try {
      await conn.execute('UPDATE work_orders SET verification_code = ? WHERE id = ? AND (verification_code IS NULL OR verification_code = "")', [
        code,
        orderId,
      ]);
    } catch {
    }
    return code;
  }

  async findOrderByCode(input: { empresaId: number; code: string }): Promise<PortalOrder | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const code = normalizeAlphaNum(input.code);
      if (!code) return null;
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT o.*, COALESCE(c.company_name, c.first_name, '') as client_name
        FROM work_orders o
        LEFT JOIN clients c ON c.id = o.client_id
        WHERE REPLACE(REPLACE(REPLACE(UPPER(COALESCE(o.verification_code,'')),'-',''),' ',''),'_','') = ?
        ORDER BY o.id DESC
        LIMIT 1
        `,
        [code],
      );
      const r = rows?.[0];
      if (!r) return null;
      const order = this.mapOrderRow(r);
      order.verificationCode = await this.ensureVerificationCode(conn, order.id);
      return order;
    } finally {
      await conn.end();
    }
  }

  async findOrderByIdOrNumber(input: { empresaId: number; query: string }): Promise<PortalOrder | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const q = (input.query ?? '').trim();
      if (!q) return null;

      const digits = q.replace(/[^0-9]/g, '');
      const orderId = digits && digits === q ? Number(digits) : null;
      const orderNumber = q.toUpperCase();

      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT o.*, COALESCE(c.company_name, c.first_name, '') as client_name
        FROM work_orders o
        LEFT JOIN clients c ON c.id = o.client_id
        WHERE (${orderId ? 'o.id = ? OR' : ''} UPPER(COALESCE(o.order_number,'')) = ?)
        ORDER BY o.id DESC
        LIMIT 1
        `,
        orderId ? [orderId, orderNumber] : [orderNumber],
      );
      const r = rows?.[0];
      if (!r) return null;
      const order = this.mapOrderRow(r);
      order.verificationCode = await this.ensureVerificationCode(conn, order.id);
      return order;
    } finally {
      await conn.end();
    }
  }

  async findLatestOrderByClientIdentifier(input: { empresaId: number; clientIdText: string }): Promise<PortalOrder | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const raw = (input.clientIdText ?? '').trim();
      if (!raw) return null;
      const normalized = raw.replace(/[- ./_]/g, '');
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT o.*, COALESCE(c.company_name, c.first_name, '') as client_name
        FROM work_orders o
        INNER JOIN clients c ON c.id = o.client_id
        WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.tax_id,'-',''),' ',''),'.',''),'/',''),'_','') = ?
           OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.id_number,'-',''),' ',''),'.',''),'/',''),'_','') = ?
           OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.phone,'-',''),' ',''),'.',''),'/',''),'_','') = ?
        ORDER BY o.id DESC
        LIMIT 1
        `,
        [normalized, normalized, normalized],
      );
      const r = rows?.[0];
      if (!r) return null;
      const order = this.mapOrderRow(r);
      order.verificationCode = await this.ensureVerificationCode(conn, order.id);
      return order;
    } finally {
      await conn.end();
    }
  }

  async history(input: { empresaId: number; orderId: number }): Promise<PortalHistoryItem[]> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, status, created_at
        FROM order_status_history
        WHERE order_id = ?
        ORDER BY id DESC
        LIMIT 200
        `,
        [input.orderId],
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        status: String(r.status ?? ''),
        createdAt: String(r.created_at ?? ''),
      }));
    } finally {
      await conn.end();
    }
  }

  async submitApproval(input: {
    empresaId: number;
    orderId: number;
    verificationCode: string;
    decision: 'approve' | 'reject';
    comment: string | null;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const orderId = Number(input.orderId);
      if (!Number.isFinite(orderId) || orderId <= 0) return false;
      const code = normalizeAlphaNum(input.verificationCode);
      if (!code) return false;

      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, estimated_cost, approval_status, verification_code
        FROM work_orders
        WHERE id = ?
        LIMIT 1
        `,
        [orderId],
      );
      const r = rows?.[0];
      if (!r) return false;
      const dbCode = normalizeAlphaNum(String(r.verification_code ?? ''));
      if (dbCode !== code) return false;

      const next = input.decision === 'approve' ? 'approved' : 'rejected';
      const approvedAt = input.decision === 'approve' ? new Date().toISOString().slice(0, 19).replace('T', ' ') : null;
      const approvedQuoteAmount = input.decision === 'approve' ? Number(r.estimated_cost ?? 0) : null;

      await conn.execute(
        `
        UPDATE work_orders
        SET approval_status = ?,
            approved_at = ?,
            approved_quote_amount = ?,
            approval_comment = ?,
            updated_at = NOW()
        WHERE id = ?
        `,
        [next, approvedAt, approvedQuoteAmount, input.comment, orderId],
      );

      try {
        await conn.execute(
          'INSERT INTO order_status_history (order_id, status, user_id, created_at) VALUES (?, ?, NULL, NOW())',
          [orderId, next],
        );
      } catch {
      }

      try {
        await this.ensureNotificationsSchema();
        const title = input.decision === 'approve' ? 'Orden aprobada por cliente' : 'Orden rechazada por cliente';
        const body = `Orden ${orderId} ${input.decision === 'approve' ? 'aprobada' : 'rechazada'} vía portal`;
        const [nr] = await this.masterPool.execute(
          `
          INSERT INTO notifications (empresa_id, title, body, type, created_at)
          VALUES (?, ?, ?, 'portal', NOW())
          `,
          [input.empresaId, title, body],
        );
        const notificationId = Number((nr as unknown as { insertId?: number })?.insertId ?? 0);
        if (notificationId) {
          const [users] = await this.masterPool.query<RowDataPacket[]>(
            `
            SELECT id
            FROM usuarios_master
            WHERE empresa_id = ? AND activo = 1 AND rol IN ('ADMIN','admin')
            `,
            [input.empresaId],
          );
          const userIds = (users ?? []).map((u) => Number(u.id)).filter((x) => Number.isFinite(x) && x > 0);
          if (userIds.length > 0) {
            const values = userIds.map((uid) => [input.empresaId, uid, notificationId, 0, null, new Date()]);
            await this.masterPool.query(
              `
              INSERT INTO user_notifications (empresa_id, user_id, notification_id, is_read, read_at, created_at)
              VALUES ?
              `,
              [values],
            );
          }
        }
      } catch {
      }

      return true;
    } finally {
      await conn.end();
    }
  }
}
