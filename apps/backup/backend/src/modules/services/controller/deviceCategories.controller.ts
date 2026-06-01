import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { DeviceCategoriesDao, type DeviceCategoryRow } from '../daos/deviceCategories.dao';

@Injectable()
export class DeviceCategoriesController {
  constructor(private readonly categoriesDao: DeviceCategoriesDao) {}

  async list(input: { empresaId: number; onlyActive?: boolean }): Promise<ApiResponse<{ items: DeviceCategoryRow[] }>> {
    try {
      const onlyActive = input.onlyActive === undefined ? null : Boolean(input.onlyActive);
      const items = await this.categoriesDao.list({ empresaId: input.empresaId, onlyActive });
      return { ok: true, data: { items } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async create(input: { empresaId: number; name: string; description?: string; sortOrder?: number }): Promise<ApiResponse<DeviceCategoryRow>> {
    const name = input.name.trim();
    if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'El nombre es obligatorio' } };
    const sortOrder = Number(input.sortOrder ?? 0);
    const desc = (input.description ?? '').trim() || null;
    try {
      const dup = await this.categoriesDao.existsByName({ empresaId: input.empresaId, name });
      if (dup) return { ok: false, error: { code: 'DUPLICATE', message: 'Ya existe una categoría con ese nombre' } };
      const id = await this.categoriesDao.create({ empresaId: input.empresaId, name, description: desc, sortOrder: Number.isFinite(sortOrder) ? sortOrder : 0 });
      const created = await this.categoriesDao.getById({ empresaId: input.empresaId, id });
      if (!created) return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
      return { ok: true, data: created };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async update(input: { empresaId: number; id: number; name: string; description?: string; sortOrder?: number; active: boolean }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    const name = input.name.trim();
    if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'El nombre es obligatorio' } };
    const sortOrder = Number(input.sortOrder ?? 0);
    const desc = (input.description ?? '').trim() || null;
    try {
      const existing = await this.categoriesDao.getById({ empresaId: input.empresaId, id });
      if (!existing) return { ok: false, error: { code: 'NOT_FOUND', message: 'Categoría no encontrada' } };
      const dup = await this.categoriesDao.existsByName({ empresaId: input.empresaId, name, idToExclude: id });
      if (dup) return { ok: false, error: { code: 'DUPLICATE', message: 'Ya existe una categoría con ese nombre' } };
      await this.categoriesDao.update({
        empresaId: input.empresaId,
        id,
        name,
        description: desc,
        sortOrder: Number.isFinite(sortOrder) ? sortOrder : 0,
        active: Boolean(input.active),
      });
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async delete(input: { empresaId: number; id: number }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const existing = await this.categoriesDao.getById({ empresaId: input.empresaId, id });
      if (!existing) return { ok: false, error: { code: 'NOT_FOUND', message: 'Categoría no encontrada' } };
      const can = await this.categoriesDao.canDelete({ empresaId: input.empresaId, id });
      if (!can) return { ok: false, error: { code: 'HAS_SERVICES', message: 'No se puede eliminar: tiene servicios asociados' } };
      await this.categoriesDao.delete({ empresaId: input.empresaId, id });
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

