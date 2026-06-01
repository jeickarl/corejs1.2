import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';

export type NotificationRow = {
  id: number;
  title: string;
  body: string;
  type: string;
  createdAt: string;
  isRead: boolean;
};

@Injectable()
export class NotificationsDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureSchema() {
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

  async listForUser(input: {
    empresaId: number;
    userId: number;
    onlyUnread: boolean;
    limit: number;
    offset: number;
  }): Promise<{ rows: NotificationRow[]; total: number; unreadCount: number }> {
    await this.ensureSchema();
    const whereUnread = input.onlyUnread ? 'AND un.is_read = 0' : '';

    const [countRows] = await this.masterPool.query<RowDataPacket[]>(
      `
      SELECT COUNT(*) as total
      FROM user_notifications un
      WHERE un.empresa_id = ? AND un.user_id = ?
      ${whereUnread}
      `,
      [input.empresaId, input.userId],
    );
    const total = Number(countRows?.[0]?.total ?? 0);

    const [unreadRows] = await this.masterPool.query<RowDataPacket[]>(
      `
      SELECT COUNT(*) as c
      FROM user_notifications un
      WHERE un.empresa_id = ? AND un.user_id = ? AND un.is_read = 0
      `,
      [input.empresaId, input.userId],
    );
    const unreadCount = Number(unreadRows?.[0]?.c ?? 0);

    const [rows] = await this.masterPool.query<RowDataPacket[]>(
      `
      SELECT n.id, n.title, COALESCE(n.body,'') as body, COALESCE(n.type,'') as type, n.created_at, un.is_read
      FROM user_notifications un
      INNER JOIN notifications n ON n.id = un.notification_id
      WHERE un.empresa_id = ? AND un.user_id = ?
      ${whereUnread}
      ORDER BY un.created_at DESC, un.id DESC
      LIMIT ? OFFSET ?
      `,
      [input.empresaId, input.userId, input.limit, input.offset],
    );

    return {
      total,
      unreadCount,
      rows: (rows ?? []).map((r) => ({
        id: Number(r.id),
        title: String(r.title ?? ''),
        body: String(r.body ?? ''),
        type: String(r.type ?? ''),
        createdAt: String(r.created_at ?? ''),
        isRead: Number(r.is_read ?? 0) === 1,
      })),
    };
  }

  async markRead(input: { empresaId: number; userId: number; notificationId: number }): Promise<boolean> {
    await this.ensureSchema();
    const [r] = await this.masterPool.execute(
      `
      UPDATE user_notifications
      SET is_read = 1, read_at = NOW()
      WHERE empresa_id = ? AND user_id = ? AND notification_id = ?
      `,
      [input.empresaId, input.userId, input.notificationId],
    );
    return Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0) > 0;
  }

  async markAllRead(input: { empresaId: number; userId: number }): Promise<number> {
    await this.ensureSchema();
    const [r] = await this.masterPool.execute(
      `
      UPDATE user_notifications
      SET is_read = 1, read_at = NOW()
      WHERE empresa_id = ? AND user_id = ? AND is_read = 0
      `,
      [input.empresaId, input.userId],
    );
    return Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0);
  }

  async createForEmpresaUsers(input: {
    empresaId: number;
    userIds: number[];
    title: string;
    body: string;
    type: string;
  }): Promise<number> {
    await this.ensureSchema();
    if (input.userIds.length === 0) return 0;
    const [r] = await this.masterPool.execute(
      `
      INSERT INTO notifications (empresa_id, title, body, type, created_at)
      VALUES (?, ?, ?, ?, NOW())
      `,
      [input.empresaId, input.title, input.body, input.type],
    );
    const notificationId = Number((r as unknown as { insertId?: number })?.insertId ?? 0);
    const values = input.userIds.map((uid) => [input.empresaId, uid, notificationId, 0, null, new Date()]);
    try {
      await this.masterPool.query(
        `
        INSERT INTO user_notifications (empresa_id, user_id, notification_id, is_read, read_at, created_at)
        VALUES ?
        `,
        [values],
      );
    } catch {
    }
    return notificationId;
  }

  async listEmpresaAdmins(input: { empresaId: number }): Promise<number[]> {
    await this.ensureSchema();
    try {
      const [rows] = await this.masterPool.query<RowDataPacket[]>(
        `
        SELECT id
        FROM usuarios_master
        WHERE empresa_id = ? AND activo = 1 AND rol IN ('ADMIN','admin')
        `,
        [input.empresaId],
      );
      return (rows ?? []).map((r) => Number(r.id)).filter((x) => Number.isFinite(x) && x > 0);
    } catch {
      return [];
    }
  }
}

