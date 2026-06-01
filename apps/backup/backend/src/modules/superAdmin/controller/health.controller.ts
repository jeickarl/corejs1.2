import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { HealthDao } from '../daos/health.dao';

@Injectable()
export class HealthController {
  constructor(private readonly healthDao: HealthDao) {}

  async tenants(): Promise<ApiResponse<Awaited<ReturnType<HealthDao['tenantsHealth']>>>> {
    try {
      const rows = await this.healthDao.tenantsHealth();
      return { ok: true, data: rows };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

