import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { SuppliersDao } from '../daos/suppliers.dao';
import { PurchaseOrdersDao, type PurchaseOrderRow } from '../daos/purchaseOrders.dao';

@Injectable()
export class PurchaseOrdersController {
  constructor(
    private readonly suppliersDao: SuppliersDao,
    private readonly purchaseOrdersDao: PurchaseOrdersDao,
  ) {}

  async list(input: {
    empresaId: number;
    search: string;
    supplierId?: number;
    status?: string;
    page: number;
    perPage: number;
  }): Promise<ApiResponse<{ items: PurchaseOrderRow[]; page: number; perPage: number; total: number }>> {
    const page = Number.isFinite(input.page) && input.page > 0 ? Math.floor(input.page) : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? Math.floor(input.perPage) : 10;
    const limit = Math.min(100, perPage);
    const offset = (page - 1) * limit;
    const supplierId = input.supplierId && Number.isFinite(Number(input.supplierId)) ? Number(input.supplierId) : null;
    const status = (input.status ?? '').trim() || null;

    try {
      const { rows, total } = await this.purchaseOrdersDao.list({
        empresaId: input.empresaId,
        search: input.search,
        supplierId,
        status,
        limit,
        offset,
      });
      return { ok: true, data: { items: rows, page, perPage: limit, total } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<ApiResponse<PurchaseOrderRow>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    try {
      const po = await this.purchaseOrdersDao.getById({ empresaId: input.empresaId, id });
      if (!po) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Orden de compra no encontrada' } };
      }
      return { ok: true, data: po };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async create(input: {
    empresaId: number;
    supplierId: number;
    orderDate: string;
    expectedDate?: string;
    paymentMethod?: string;
    paymentTerms?: string;
    notes?: string;
    createdByUserId: number | null;
  }): Promise<ApiResponse<PurchaseOrderRow>> {
    const supplierId = Number(input.supplierId);
    if (!Number.isFinite(supplierId) || supplierId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Proveedor inválido' } };
    }
    const orderDate = (input.orderDate ?? '').trim();
    if (!orderDate) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'La fecha de la orden es obligatoria' } };
    }

    const expectedDate = (input.expectedDate ?? '').trim() || null;
    const paymentMethod = (input.paymentMethod ?? '').trim() || null;
    const paymentTerms = (input.paymentTerms ?? '').trim() || null;
    const notes = (input.notes ?? '').trim() || null;

    try {
      const supplier = await this.suppliersDao.getById({ empresaId: input.empresaId, id: supplierId });
      if (!supplier || !supplier.isActive) {
        return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Proveedor no válido o inactivo' } };
      }

      const id = await this.purchaseOrdersDao.create({
        empresaId: input.empresaId,
        supplierId,
        orderDate,
        expectedDate,
        paymentMethod,
        paymentTerms,
        notes,
        createdByUserId: input.createdByUserId,
      });
      const created = await this.purchaseOrdersDao.getById({ empresaId: input.empresaId, id });
      if (!created) {
        return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
      }
      return { ok: true, data: created };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async update(input: {
    empresaId: number;
    id: number;
    supplierId: number;
    orderDate: string;
    expectedDate?: string;
    paymentMethod?: string;
    paymentTerms?: string;
    notes?: string;
    status?: string;
  }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    const supplierId = Number(input.supplierId);
    if (!Number.isFinite(supplierId) || supplierId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Proveedor inválido' } };
    }
    const orderDate = (input.orderDate ?? '').trim();
    if (!orderDate) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'La fecha de la orden es obligatoria' } };
    }

    const expectedDate = (input.expectedDate ?? '').trim() || null;
    const paymentMethod = (input.paymentMethod ?? '').trim() || null;
    const paymentTerms = (input.paymentTerms ?? '').trim() || null;
    const notes = (input.notes ?? '').trim() || null;
    const status = (input.status ?? '').trim() || null;

    try {
      const existing = await this.purchaseOrdersDao.getById({ empresaId: input.empresaId, id });
      if (!existing) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Orden de compra no encontrada' } };
      }

      const supplier = await this.suppliersDao.getById({ empresaId: input.empresaId, id: supplierId });
      if (!supplier || !supplier.isActive) {
        return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Proveedor no válido o inactivo' } };
      }

      await this.purchaseOrdersDao.update({
        empresaId: input.empresaId,
        id,
        supplierId,
        orderDate,
        expectedDate,
        paymentMethod,
        paymentTerms,
        notes,
        status,
      });
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async cancel(input: { empresaId: number; id: number }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    try {
      const existing = await this.purchaseOrdersDao.getById({ empresaId: input.empresaId, id });
      if (!existing) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Orden de compra no encontrada' } };
      }
      await this.purchaseOrdersDao.cancel({ empresaId: input.empresaId, id });
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

