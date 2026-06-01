import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { InventoryDao, type InventoryMovementRow, type InventoryProductRow } from '../daos/inventory.dao';

function asNumber(v: unknown): number {
  const n = Number(v ?? 0);
  if (!Number.isFinite(n)) return 0;
  return Math.round(n * 100) / 100;
}

@Injectable()
export class InventoryController {
  constructor(private readonly inventoryDao: InventoryDao) {}

  async listProducts(input: {
    empresaId: number;
    search: string;
    page: number;
    perPage: number;
    onlyActive?: boolean;
  }): Promise<ApiResponse<{ items: InventoryProductRow[]; page: number; perPage: number; total: number }>> {
    const page = Number.isFinite(input.page) && input.page > 0 ? input.page : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? input.perPage : 10;
    const offset = (page - 1) * perPage;
    try {
      const { rows, total } = await this.inventoryDao.list({
        empresaId: input.empresaId,
        search: input.search,
        limit: perPage,
        offset,
        onlyActive: input.onlyActive,
      });
      return { ok: true, data: { items: rows, page, perPage, total } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async getProduct(input: { empresaId: number; id: number }): Promise<ApiResponse<InventoryProductRow>> {
    try {
      const r = await this.inventoryDao.getById(input);
      if (!r) return { ok: false, error: { code: 'NOT_FOUND', message: 'Producto no encontrado' } };
      return { ok: true, data: r };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async createProduct(input: {
    empresaId: number;
    sku?: string | null;
    name: string;
    description?: string | null;
    salePrice?: number;
    costPrice?: number;
    currentStock?: number;
    minStock?: number;
    isActive?: boolean;
  }): Promise<ApiResponse<{ id: number }>> {
    const name = (input.name ?? '').trim();
    if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Nombre obligatorio' } };
    try {
      const id = await this.inventoryDao.createProduct({
        empresaId: input.empresaId,
        sku: (input.sku ?? '').trim() || null,
        name,
        description: (input.description ?? '').trim() || null,
        salePrice: asNumber(input.salePrice),
        costPrice: asNumber(input.costPrice),
        currentStock: asNumber(input.currentStock),
        minStock: asNumber(input.minStock),
        isActive: input.isActive === false ? false : true,
      });
      return { ok: true, data: { id } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async updateProduct(input: {
    empresaId: number;
    id: number;
    sku?: string | null;
    name: string;
    description?: string | null;
    salePrice?: number;
    costPrice?: number;
    minStock?: number;
    isActive?: boolean;
  }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    const name = (input.name ?? '').trim();
    if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Nombre obligatorio' } };
    try {
      const ok = await this.inventoryDao.updateProduct({
        empresaId: input.empresaId,
        id,
        sku: (input.sku ?? '').trim() || null,
        name,
        description: (input.description ?? '').trim() || null,
        salePrice: asNumber(input.salePrice),
        costPrice: asNumber(input.costPrice),
        minStock: asNumber(input.minStock),
        isActive: input.isActive === false ? false : true,
      });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'Producto no encontrado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async deleteProduct(input: { empresaId: number; id: number }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const ok = await this.inventoryDao.deactivateProduct({ empresaId: input.empresaId, id });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'Producto no encontrado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async adjustStock(input: {
    empresaId: number;
    productId: number;
    movementType: 'in' | 'out' | 'adjust';
    quantity: number;
    notes?: string | null;
    createdBy?: number | null;
    referenceType?: string | null;
    referenceId?: number | null;
  }): Promise<ApiResponse<{ movementId: number; newStock: number }>> {
    const productId = Number(input.productId);
    if (!Number.isFinite(productId) || productId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Producto inválido' } };
    }
    const qty = asNumber(input.quantity);
    if (qty <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Cantidad inválida' } };
    try {
      const r = await this.inventoryDao.addMovement({
        empresaId: input.empresaId,
        productId,
        movementType: input.movementType,
        quantity: qty,
        notes: (input.notes ?? '').trim() || null,
        createdBy: input.createdBy ?? null,
        referenceType: input.referenceType ?? null,
        referenceId: input.referenceId ?? null,
      });
      if (!r.ok) {
        if (r.code === 'INSUFFICIENT_STOCK') {
          return { ok: false, error: { code: 'INSUFFICIENT_STOCK', message: 'Stock insuficiente' } };
        }
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Producto no encontrado' } };
      }
      return { ok: true, data: { movementId: r.movementId, newStock: r.newStock } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async listMovements(input: {
    empresaId: number;
    productId: number;
    page: number;
    perPage: number;
  }): Promise<ApiResponse<{ items: InventoryMovementRow[]; page: number; perPage: number; total: number }>> {
    const page = Number.isFinite(input.page) && input.page > 0 ? input.page : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? input.perPage : 10;
    const offset = (page - 1) * perPage;
    try {
      const { rows, total } = await this.inventoryDao.listMovements({
        empresaId: input.empresaId,
        productId: input.productId,
        limit: perPage,
        offset,
      });
      return { ok: true, data: { items: rows, page, perPage, total } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

