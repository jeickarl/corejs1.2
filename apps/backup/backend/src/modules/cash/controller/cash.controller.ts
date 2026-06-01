import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { CashDao, type CashMovementRow, type CashSessionRow } from '../daos/cash.dao';

@Injectable()
export class CashController {
  constructor(private readonly cashDao: CashDao) {}

  async me(input: { empresaId: number }): Promise<ApiResponse<CashSessionRow | null>> {
    try {
      const session = await this.cashDao.getOpenSession({ empresaId: input.empresaId });
      return { ok: true, data: session };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async open(input: { empresaId: number; openedBy: number | null; initialAmount: number }): Promise<ApiResponse<{ id: number }>> {
    try {
      const id = await this.cashDao.openSession({
        empresaId: input.empresaId,
        openedBy: input.openedBy,
        initialAmount: input.initialAmount,
      });
      if (!id) return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
      return { ok: true, data: { id } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async close(input: {
    empresaId: number;
    closedBy: number | null;
    finalAmount: number;
    physicalCount?: number | null;
  }): Promise<ApiResponse<{ closed: true }>> {
    try {
      const ok = await this.cashDao.closeSession({
        empresaId: input.empresaId,
        closedBy: input.closedBy,
        finalAmount: input.finalAmount,
        physicalCount: input.physicalCount ?? null,
      });
      if (!ok) return { ok: false, error: { code: 'NO_OPEN_SESSION', message: 'No hay caja abierta' } };
      return { ok: true, data: { closed: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async movements(input: {
    empresaId: number;
    cashSessionId: number;
    limit: number;
  }): Promise<ApiResponse<CashMovementRow[]>> {
    try {
      const rows = await this.cashDao.movements({
        empresaId: input.empresaId,
        cashSessionId: input.cashSessionId,
        limit: input.limit,
      });
      return { ok: true, data: rows };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async summary(input: { empresaId: number; cashSessionId: number }): Promise<ApiResponse<{ totalIncome: number; totalExpense: number; systemTotal: number }>> {
    try {
      const totals = await this.cashDao.computeSessionTotals({
        empresaId: input.empresaId,
        cashSessionId: input.cashSessionId,
      });
      return { ok: true, data: totals };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

