import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { DbPoolDao } from '../daos/dbPool.dao';

@Injectable()
export class DbPoolController {
  constructor(private readonly dbPoolDao: DbPoolDao) {}

  async list(): Promise<ApiResponse<{ stats: Awaited<ReturnType<DbPoolDao['stats']>>; items: Awaited<ReturnType<DbPoolDao['list']>> }>> {
    try {
      const [stats, items] = await Promise.all([this.dbPoolDao.stats(), this.dbPoolDao.list()]);
      return { ok: true, data: { stats, items } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async add(input: {
    dbHost: string;
    dbPort: number;
    dbName: string;
    dbUser: string;
    dbPass: string;
  }): Promise<ApiResponse<{ id: number }>> {
    try {
      const id = await this.dbPoolDao.add(input);
      if (!id) return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
      return { ok: true, data: { id } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async syncFromEmpresas(): Promise<ApiResponse<{ added: number; skipped: number }>> {
    try {
      const r = await this.dbPoolDao.syncFromEmpresas();
      return { ok: true, data: r };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async markAvailable(id: number): Promise<ApiResponse<{ done: true }>> {
    try {
      const ok = await this.dbPoolDao.markAvailable(id);
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado o no permitido' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

