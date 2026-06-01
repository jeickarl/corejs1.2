import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { DeviceCategoriesDao } from '../daos/deviceCategories.dao';
import { ServicesDao, type ServiceRow } from '../daos/services.dao';

@Injectable()
export class ServicesController {
  constructor(
    private readonly categoriesDao: DeviceCategoriesDao,
    private readonly servicesDao: ServicesDao,
  ) {}

  async list(input: {
    empresaId: number;
    search: string;
    categoryId?: number;
    onlyActive?: boolean;
    page: number;
    perPage: number;
  }): Promise<ApiResponse<{ items: ServiceRow[]; page: number; perPage: number; total: number }>> {
    const page = Number.isFinite(input.page) && input.page > 0 ? Math.floor(input.page) : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? Math.floor(input.perPage) : 10;
    const limit = Math.min(100, perPage);
    const offset = (page - 1) * limit;
    const categoryId = input.categoryId && Number.isFinite(Number(input.categoryId)) ? Number(input.categoryId) : null;
    const onlyActive = input.onlyActive === undefined ? null : Boolean(input.onlyActive);
    try {
      const { rows, total } = await this.servicesDao.list({
        empresaId: input.empresaId,
        search: input.search,
        categoryId,
        onlyActive,
        limit,
        offset,
      });
      return { ok: true, data: { items: rows, page, perPage: limit, total } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<ApiResponse<ServiceRow>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const s = await this.servicesDao.getById({ empresaId: input.empresaId, id });
      if (!s) return { ok: false, error: { code: 'NOT_FOUND', message: 'Servicio no encontrado' } };
      return { ok: true, data: s };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async create(input: {
    empresaId: number;
    name: string;
    description?: string;
    deviceCategoryId: number;
    basePrice?: number;
    estimatedTime?: number;
    notes?: string;
  }): Promise<ApiResponse<ServiceRow>> {
    const name = input.name.trim();
    if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'El nombre es obligatorio' } };
    const deviceCategoryId = Number(input.deviceCategoryId);
    if (!Number.isFinite(deviceCategoryId) || deviceCategoryId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Categoría inválida' } };
    }
    const basePrice = Number(input.basePrice ?? 0);
    const estimatedTime = Number(input.estimatedTime ?? 0);
    if (!Number.isFinite(basePrice) || basePrice < 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Precio inválido' } };
    }
    if (!Number.isFinite(estimatedTime) || estimatedTime < 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Tiempo inválido' } };
    }
    const desc = (input.description ?? '').trim() || null;
    const notes = (input.notes ?? '').trim() || null;

    try {
      const cat = await this.categoriesDao.getById({ empresaId: input.empresaId, id: deviceCategoryId });
      if (!cat || !cat.active) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Categoría inválida o inactiva' } };
      const dup = await this.servicesDao.existsByName({ empresaId: input.empresaId, name });
      if (dup) return { ok: false, error: { code: 'DUPLICATE', message: 'Ya existe un servicio con ese nombre' } };

      const id = await this.servicesDao.create({
        empresaId: input.empresaId,
        name,
        description: desc,
        deviceCategoryId,
        basePrice,
        estimatedTime: Math.floor(estimatedTime),
        notes,
      });
      const created = await this.servicesDao.getById({ empresaId: input.empresaId, id });
      if (!created) return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
      return { ok: true, data: created };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async update(input: {
    empresaId: number;
    id: number;
    name: string;
    description?: string;
    deviceCategoryId: number;
    basePrice?: number;
    estimatedTime?: number;
    notes?: string;
    active: boolean;
  }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    const name = input.name.trim();
    if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'El nombre es obligatorio' } };
    const deviceCategoryId = Number(input.deviceCategoryId);
    if (!Number.isFinite(deviceCategoryId) || deviceCategoryId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Categoría inválida' } };
    }
    const basePrice = Number(input.basePrice ?? 0);
    const estimatedTime = Number(input.estimatedTime ?? 0);
    if (!Number.isFinite(basePrice) || basePrice < 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Precio inválido' } };
    }
    if (!Number.isFinite(estimatedTime) || estimatedTime < 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Tiempo inválido' } };
    }
    const desc = (input.description ?? '').trim() || null;
    const notes = (input.notes ?? '').trim() || null;

    try {
      const existing = await this.servicesDao.getById({ empresaId: input.empresaId, id });
      if (!existing) return { ok: false, error: { code: 'NOT_FOUND', message: 'Servicio no encontrado' } };
      const cat = await this.categoriesDao.getById({ empresaId: input.empresaId, id: deviceCategoryId });
      if (!cat) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Categoría inválida' } };
      const dup = await this.servicesDao.existsByName({ empresaId: input.empresaId, name, idToExclude: id });
      if (dup) return { ok: false, error: { code: 'DUPLICATE', message: 'Ya existe un servicio con ese nombre' } };

      await this.servicesDao.update({
        empresaId: input.empresaId,
        id,
        name,
        description: desc,
        deviceCategoryId,
        basePrice,
        estimatedTime: Math.floor(estimatedTime),
        notes,
        active: Boolean(input.active),
      });
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

