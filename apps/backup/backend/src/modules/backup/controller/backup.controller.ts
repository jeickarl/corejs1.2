import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { BackupDao, type BackupPayload } from '../daos/backup.dao';

@Injectable()
export class BackupController {
  constructor(private readonly backupDao: BackupDao) {}

  async exportTenant(input: { empresaId: number }): Promise<ApiResponse<BackupPayload>> {
    try {
      const payload = await this.backupDao.exportTenant({ empresaId: input.empresaId });
      return { ok: true, data: payload };
    } catch {
      return { ok: false, error: { code: 'BACKUP_EXPORT_ERROR', message: 'No se pudo exportar' } };
    }
  }

  async importTenant(input: { empresaId: number; payload: BackupPayload; mode: 'replace' | 'append' }): Promise<ApiResponse<{ done: true }>> {
    if (!input.payload || input.payload.kind !== 'corejs-backup' || input.payload.version !== 1) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Backup inválido' } };
    }
    try {
      await this.backupDao.importTenant({ empresaId: input.empresaId, backup: input.payload, mode: input.mode });
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'BACKUP_IMPORT_ERROR', message: 'No se pudo importar' } };
    }
  }
}

