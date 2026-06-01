import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { PurchaseOrdersDao } from '../daos/purchaseOrders.dao';
import { SuppliersDao } from '../daos/suppliers.dao';
import { PurchaseReceiptsDao, type PurchaseReceiptRow } from '../daos/purchaseReceipts.dao';

@Injectable()
export class PurchaseReceiptsController {
  constructor(
    private readonly purchaseOrdersDao: PurchaseOrdersDao,
    private readonly suppliersDao: SuppliersDao,
    private readonly purchaseReceiptsDao: PurchaseReceiptsDao,
  ) {}

  async list(input: { empresaId: number; search: string; page: number; perPage: number }): Promise<ApiResponse<{ items: PurchaseReceiptRow[]; page: number; perPage: number; total: number }>> {
    const page = Number.isFinite(input.page) && input.page > 0 ? Math.floor(input.page) : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? Math.floor(input.perPage) : 10;
    const limit = Math.min(100, perPage);
    const offset = (page - 1) * limit;
    try {
      const { rows, total } = await this.purchaseReceiptsDao.list({
        empresaId: input.empresaId,
        search: input.search,
        limit,
        offset,
      });
      return { ok: true, data: { items: rows, page, perPage: limit, total } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<ApiResponse<PurchaseReceiptRow>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    try {
      const r = await this.purchaseReceiptsDao.getById({ empresaId: input.empresaId, id });
      if (!r) return { ok: false, error: { code: 'NOT_FOUND', message: 'Recepción no encontrada' } };
      return { ok: true, data: r };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async create(input: {
    empresaId: number;
    purchaseOrderId: number;
    receivedDate: string;
    notes?: string;
    createdBy: number | null;
    items: Array<{ productId: number; quantity: number; unitCost: number }>;
  }): Promise<ApiResponse<{ id: number }>> {
    const purchaseOrderId = Number(input.purchaseOrderId);
    if (!Number.isFinite(purchaseOrderId) || purchaseOrderId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Orden inválida' } };
    }
    const receivedDate = (input.receivedDate ?? '').trim();
    if (!receivedDate) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Fecha inválida' } };
    }
    if (!Array.isArray(input.items) || input.items.length === 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Agrega al menos un ítem' } };
    }
    for (const it of input.items) {
      const pid = Number(it.productId);
      const qty = Number(it.quantity);
      const unit = Number(it.unitCost);
      if (!Number.isFinite(pid) || pid <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Producto inválido' } };
      if (!Number.isFinite(qty) || qty <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Cantidad inválida' } };
      if (!Number.isFinite(unit) || unit < 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Costo inválido' } };
    }

    try {
      const po = await this.purchaseOrdersDao.getById({ empresaId: input.empresaId, id: purchaseOrderId });
      if (!po) return { ok: false, error: { code: 'NOT_FOUND', message: 'Orden no encontrada' } };
      if (po.status === 'cancelled') return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Orden cancelada' } };
      const supplier = await this.suppliersDao.getById({ empresaId: input.empresaId, id: po.supplierId });
      if (!supplier) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Proveedor inválido' } };

      const id = await this.purchaseReceiptsDao.createReceipt({
        empresaId: input.empresaId,
        purchaseOrderId,
        supplierId: po.supplierId,
        receivedDate,
        notes: (input.notes ?? '').trim() || null,
        createdBy: input.createdBy,
        items: input.items.map((x) => ({ productId: Number(x.productId), quantity: Number(x.quantity), unitCost: Number(x.unitCost) })),
      });
      return { ok: true, data: { id } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

