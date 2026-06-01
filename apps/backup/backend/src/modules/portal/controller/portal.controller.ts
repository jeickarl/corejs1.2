import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { PortalDao, type PortalConfig, type PortalHistoryItem, type PortalOrder } from '../daos/portal.dao';

export type PortalVerifyResult = {
  foundByCode: boolean;
  order: PortalOrder;
  config: PortalConfig;
  history: PortalHistoryItem[];
  canApprove: boolean;
};

@Injectable()
export class PortalController {
  constructor(private readonly portalDao: PortalDao) {}

  async config(input: { empresaId: number }): Promise<ApiResponse<PortalConfig>> {
    try {
      const cfg = await this.portalDao.getPortalConfig({ empresaId: input.empresaId });
      return { ok: true, data: cfg };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async verify(input: { empresaId: number; mode: 'order' | 'id'; query: string }): Promise<ApiResponse<PortalVerifyResult>> {
    const q = (input.query ?? '').trim();
    if (!q) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Dato requerido' } };
    try {
      const cfg = await this.portalDao.getPortalConfig({ empresaId: input.empresaId });
      let foundByCode = false;
      let order: PortalOrder | null = null;

      if (input.mode === 'id') {
        if (!cfg.enableLookupById) {
          return { ok: false, error: { code: 'DISABLED', message: 'Búsqueda por documento deshabilitada' } };
        }
        order = await this.portalDao.findLatestOrderByClientIdentifier({ empresaId: input.empresaId, clientIdText: q });
      } else {
        const hasLetters = /[A-Za-z]/.test(q);
        if (hasLetters) {
          order = await this.portalDao.findOrderByCode({ empresaId: input.empresaId, code: q });
          foundByCode = Boolean(order);
        }
        if (!order) {
          order = await this.portalDao.findOrderByIdOrNumber({ empresaId: input.empresaId, query: q });
        }
      }

      if (!order) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Orden no encontrada' } };
      }

      const history = cfg.showTimeline && foundByCode ? await this.portalDao.history({ empresaId: input.empresaId, orderId: order.id }) : [];

      const canApprove = cfg.allowApproval && foundByCode && order.approvalStatus === 'pending';
      return { ok: true, data: { foundByCode, order, config: cfg, history, canApprove } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async receipt(input: { empresaId: number; orderId: number }): Promise<ApiResponse<PortalOrder>> {
    const id = Number(input.orderId);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const order = await this.portalDao.findOrderByIdOrNumber({ empresaId: input.empresaId, query: String(id) });
      if (!order) return { ok: false, error: { code: 'NOT_FOUND', message: 'Orden no encontrada' } };
      return { ok: true, data: order };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async submitApproval(input: {
    empresaId: number;
    orderId: number;
    verificationCode: string;
    decision: 'approve' | 'reject';
    comment?: string;
  }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.orderId);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    const code = (input.verificationCode ?? '').trim();
    if (!code) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Código requerido' } };

    try {
      const cfg = await this.portalDao.getPortalConfig({ empresaId: input.empresaId });
      if (!cfg.allowApproval) {
        return { ok: false, error: { code: 'DISABLED', message: 'Aprobación deshabilitada' } };
      }
      const ok = await this.portalDao.submitApproval({
        empresaId: input.empresaId,
        orderId: id,
        verificationCode: code,
        decision: input.decision,
        comment: (input.comment ?? '').trim() || null,
      });
      if (!ok) return { ok: false, error: { code: 'NOT_ALLOWED', message: 'No autorizado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

