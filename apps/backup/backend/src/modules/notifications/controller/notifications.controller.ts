import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { NotificationsDao, type NotificationRow } from '../daos/notifications.dao';

@Injectable()
export class NotificationsController {
  constructor(private readonly notificationsDao: NotificationsDao) {}

  async list(input: {
    empresaId: number;
    userId: number;
    onlyUnread: boolean;
    page: number;
    perPage: number;
  }): Promise<ApiResponse<{ items: NotificationRow[]; page: number; perPage: number; total: number; unreadCount: number }>> {
    const page = Number.isFinite(input.page) && input.page > 0 ? Math.floor(input.page) : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? Math.floor(input.perPage) : 10;
    const limit = Math.min(100, perPage);
    const offset = (page - 1) * limit;
    try {
      const { rows, total, unreadCount } = await this.notificationsDao.listForUser({
        empresaId: input.empresaId,
        userId: input.userId,
        onlyUnread: input.onlyUnread,
        limit,
        offset,
      });
      return { ok: true, data: { items: rows, page, perPage: limit, total, unreadCount } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async markRead(input: { empresaId: number; userId: number; id: number }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      await this.notificationsDao.markRead({ empresaId: input.empresaId, userId: input.userId, notificationId: id });
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async markAllRead(input: { empresaId: number; userId: number }): Promise<ApiResponse<{ done: true }>> {
    try {
      await this.notificationsDao.markAllRead({ empresaId: input.empresaId, userId: input.userId });
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

