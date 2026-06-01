import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { RepairSchemaDao } from '../daos/repairSchema.dao';

@Injectable()
export class RepairSchemaController {
  constructor(private readonly repairSchemaDao: RepairSchemaDao) {}

  async repairTenant(tenantId: number): Promise<ApiResponse<Awaited<ReturnType<RepairSchemaDao['repairTenant']>>>> {
    try {
      const res = await this.repairSchemaDao.repairTenant(tenantId);
      return { ok: true, data: res };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async repairAll(): Promise<ApiResponse<Awaited<ReturnType<RepairSchemaDao['repairAllActive']>>>> {
    try {
      const res = await this.repairSchemaDao.repairAllActive();
      return { ok: true, data: res };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

