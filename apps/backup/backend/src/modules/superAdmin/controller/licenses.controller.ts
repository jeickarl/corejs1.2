import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { LicensesDao } from '../daos/licenses.dao';

@Injectable()
export class LicensesController {
  constructor(private readonly licensesDao: LicensesDao) {}

  async available(): Promise<ApiResponse<string[]>> {
    try {
      const codes = await this.licensesDao.listAvailableCodes();
      return { ok: true, data: codes };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async generateAndAssign(tenantId: number): Promise<ApiResponse<{ code: string }>> {
    try {
      const code = await this.licensesDao.generateAndAssign(tenantId);
      return { ok: true, data: { code } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async assign(tenantId: number, code: string): Promise<ApiResponse<{ done: true }>> {
    try {
      const ok = await this.licensesDao.assignExisting(tenantId, code);
      if (!ok) {
        return { ok: false, error: { code: 'LICENSE_NOT_AVAILABLE', message: 'Licencia no disponible o ya usada.' } };
      }
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

