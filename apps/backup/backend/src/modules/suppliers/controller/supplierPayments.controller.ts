import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { SuppliersDao } from '../daos/suppliers.dao';
import { PurchaseOrdersDao } from '../daos/purchaseOrders.dao';
import { SupplierPaymentsDao, type PendingPurchaseOrderRow, type SupplierPaymentRow } from '../daos/supplierPayments.dao';

@Injectable()
export class SupplierPaymentsController {
  constructor(
    private readonly suppliersDao: SuppliersDao,
    private readonly purchaseOrdersDao: PurchaseOrdersDao,
    private readonly supplierPaymentsDao: SupplierPaymentsDao,
  ) {}

  async recent(input: {
    empresaId: number;
    page: number;
    perPage: number;
    includeVoided?: boolean;
  }): Promise<ApiResponse<{ items: SupplierPaymentRow[]; page: number; perPage: number; total: number }>> {
    const page = Number.isFinite(input.page) && input.page > 0 ? Math.floor(input.page) : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? Math.floor(input.perPage) : 20;
    const limit = Math.min(200, perPage);
    const offset = (page - 1) * limit;

    try {
      const { rows, total } = await this.supplierPaymentsDao.listRecent({
        empresaId: input.empresaId,
        limit,
        offset,
        includeVoided: Boolean(input.includeVoided),
      });
      return { ok: true, data: { items: rows, page, perPage: limit, total } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async voided(input: {
    empresaId: number;
    page: number;
    perPage: number;
  }): Promise<ApiResponse<{ items: SupplierPaymentRow[]; page: number; perPage: number; total: number }>> {
    const page = Number.isFinite(input.page) && input.page > 0 ? Math.floor(input.page) : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? Math.floor(input.perPage) : 20;
    const limit = Math.min(200, perPage);
    const offset = (page - 1) * limit;

    try {
      const { rows, total } = await this.supplierPaymentsDao.listVoided({
        empresaId: input.empresaId,
        limit,
        offset,
      });
      return { ok: true, data: { items: rows, page, perPage: limit, total } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async pendingOrders(input: {
    empresaId: number;
    page: number;
    perPage: number;
  }): Promise<ApiResponse<{ items: PendingPurchaseOrderRow[]; page: number; perPage: number; total: number }>> {
    const page = Number.isFinite(input.page) && input.page > 0 ? Math.floor(input.page) : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? Math.floor(input.perPage) : 20;
    const limit = Math.min(200, perPage);
    const offset = (page - 1) * limit;

    try {
      const { rows, total } = await this.supplierPaymentsDao.pendingOrders({
        empresaId: input.empresaId,
        limit,
        offset,
      });
      return { ok: true, data: { items: rows, page, perPage: limit, total } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async createPayment(input: {
    empresaId: number;
    supplierId: number;
    purchaseOrderId?: number;
    paymentAmount: number;
    paymentMethod?: string;
    paymentDate: string;
    referenceNumber?: string;
    notes?: string;
    createdBy: number | null;
    requestId?: string;
  }): Promise<ApiResponse<{ id: number }>> {
    const supplierId = Number(input.supplierId);
    if (!Number.isFinite(supplierId) || supplierId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Proveedor inválido' } };
    }
    const purchaseOrderId = input.purchaseOrderId && Number.isFinite(Number(input.purchaseOrderId)) ? Number(input.purchaseOrderId) : null;
    const paymentAmount = Number(input.paymentAmount);
    if (!Number.isFinite(paymentAmount) || paymentAmount <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Monto inválido' } };
    }
    const paymentDate = (input.paymentDate ?? '').trim();
    if (!paymentDate) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Fecha inválida' } };
    }

    const paymentMethod = (input.paymentMethod ?? '').trim() || null;
    const referenceNumber = (input.referenceNumber ?? '').trim() || null;
    const notes = (input.notes ?? '').trim() || null;
    const requestId = (input.requestId ?? '').trim() || null;

    try {
      const supplier = await this.suppliersDao.getById({ empresaId: input.empresaId, id: supplierId });
      if (!supplier || !supplier.isActive) {
        return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Proveedor no válido o inactivo' } };
      }
      if (purchaseOrderId) {
        const po = await this.purchaseOrdersDao.getById({ empresaId: input.empresaId, id: purchaseOrderId });
        if (!po) {
          return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Orden de compra inválida' } };
        }
        if (po.supplierId !== supplierId) {
          return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'La orden no pertenece a ese proveedor' } };
        }
      }

      const id = await this.supplierPaymentsDao.createPayment({
        empresaId: input.empresaId,
        supplierId,
        purchaseOrderId,
        paymentAmount,
        paymentMethod,
        paymentDate,
        referenceNumber,
        notes,
        createdBy: input.createdBy,
        requestId,
      });
      return { ok: true, data: { id } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async voidPayment(input: { empresaId: number; id: number; reason: string; voidedBy: number | null }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    const reason = input.reason.trim();
    if (!reason) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Motivo requerido' } };
    }
    try {
      const ok = await this.supplierPaymentsDao.voidPayment({ empresaId: input.empresaId, id, reason, voidedBy: input.voidedBy });
      if (!ok) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Pago no encontrado' } };
      }
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

