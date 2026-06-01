import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { InvoicesDao, type InvoiceListRow, type InvoiceRow } from '../daos/invoices.dao';

type CreateInvoiceItem = {
  itemType: 'manual' | 'product' | 'service';
  productId?: number | null;
  description: string;
  quantity: number;
  unitPrice: number;
  taxPercent?: number;
};

type CreateInvoicePayment = {
  paymentAmount: number;
  paymentMethod: string;
  paymentDate?: string;
  referenceNumber?: string;
  notes?: string;
};

function asMoney(v: unknown): number {
  const n = Number(v ?? 0);
  if (!Number.isFinite(n)) return 0;
  return Math.round(n * 100) / 100;
}

@Injectable()
export class SalesController {
  constructor(private readonly invoicesDao: InvoicesDao) {}

  async listInvoices(input: {
    empresaId: number;
    search: string;
    status: string;
    paymentStatus: string;
    page: number;
    perPage: number;
  }): Promise<ApiResponse<{ items: InvoiceListRow[]; page: number; perPage: number; total: number }>> {
    const page = Number.isFinite(input.page) && input.page > 0 ? input.page : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? input.perPage : 10;
    const offset = (page - 1) * perPage;
    try {
      const { rows, total } = await this.invoicesDao.list({
        empresaId: input.empresaId,
        search: input.search,
        status: input.status,
        paymentStatus: input.paymentStatus,
        limit: perPage,
        offset,
      });
      return { ok: true, data: { items: rows, page, perPage, total } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async getInvoice(input: { empresaId: number; id: number }): Promise<ApiResponse<InvoiceRow>> {
    try {
      const inv = await this.invoicesDao.getById(input);
      if (!inv) return { ok: false, error: { code: 'NOT_FOUND', message: 'Factura no encontrada' } };
      return { ok: true, data: inv };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async createInvoice(input: {
    empresaId: number;
    createdBy: number | null;
    clientId: number;
    documentType?: string;
    invoiceDate: string;
    dueDate?: string | null;
    notes?: string;
    termsConditions?: string;
    action: 'save' | 'save_pending';
    items: CreateInvoiceItem[];
    payments?: CreateInvoicePayment[];
  }): Promise<ApiResponse<{ id: number }>> {
    const clientId = Number(input.clientId);
    if (!Number.isFinite(clientId) || clientId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Cliente inválido' } };
    }
    const invoiceDate = (input.invoiceDate ?? '').trim();
    if (!invoiceDate) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'La fecha de factura es obligatoria' } };
    }
    const items = (input.items ?? []).map((it) => ({
      itemType: it.itemType ?? 'manual',
      productId: it.productId === undefined || it.productId === null ? null : Number(it.productId),
      description: (it.description ?? '').trim(),
      quantity: asMoney(it.quantity ?? 0),
      unitPrice: asMoney(it.unitPrice ?? 0),
      taxPercent: asMoney(it.taxPercent ?? 0),
    }));
    for (const it of items) {
      if (it.itemType === 'product') {
        if (!it.productId || !Number.isFinite(it.productId) || it.productId <= 0) {
          return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Debe seleccionar un producto válido.' } };
        }
      }
    }
    const validItems = items.filter((it) => it.description && it.quantity > 0);
    if (validItems.length === 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Debe agregar al menos un ítem.' } };
    }

    const subtotal = asMoney(validItems.reduce((a, it) => a + asMoney(it.quantity * it.unitPrice), 0));
    const taxAmount = asMoney(
      validItems.reduce((a, it) => a + asMoney((asMoney(it.quantity * it.unitPrice) * asMoney(it.taxPercent)) / 100), 0),
    );
    const totalAmount = asMoney(subtotal + taxAmount);

    const payments = (input.payments ?? [])
      .map((p) => ({
        paymentAmount: asMoney(p.paymentAmount ?? 0),
        paymentMethod: (p.paymentMethod ?? '').trim(),
        paymentDate: (p.paymentDate ?? '').trim(),
        referenceNumber: (p.referenceNumber ?? '').trim() || null,
        notes: (p.notes ?? '').trim() || null,
        createdBy: input.createdBy,
      }))
      .filter((p) => p.paymentAmount > 0 && p.paymentMethod);

    const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const normalizedPayments = payments.map((p) => ({
      ...p,
      paymentDate: p.paymentDate || now,
    }));

    const status = 'sent';
    try {
      const id = await this.invoicesDao.createInvoice({
        empresaId: input.empresaId,
        clientId,
        documentType: (input.documentType ?? '').trim() || null,
        invoiceDate,
        dueDate: (input.dueDate ?? null) ? String(input.dueDate) : null,
        notes: (input.notes ?? '').trim() || null,
        termsConditions: (input.termsConditions ?? '').trim() || null,
        subtotal,
        taxAmount,
        totalAmount,
        status,
        createdBy: input.createdBy,
        items: validItems.map((it) => ({
          itemType: it.itemType,
          productId: it.productId ?? null,
          description: it.description,
          quantity: it.quantity,
          unitPrice: it.unitPrice,
          totalPrice: asMoney(it.quantity * it.unitPrice),
        })),
        payments: input.action === 'save' ? normalizedPayments : [],
      });
      return { ok: true, data: { id } };
    } catch (e) {
      const msg = String((e as { message?: string })?.message ?? '');
      if (msg === 'NO_OPEN_CASH') {
        return { ok: false, error: { code: 'NO_OPEN_SESSION', message: 'No hay una sesión de caja abierta.' } };
      }
      if (msg === 'INSUFFICIENT_STOCK') {
        return { ok: false, error: { code: 'INSUFFICIENT_STOCK', message: 'Stock insuficiente para uno o más productos.' } };
      }
      if (msg === 'PRODUCT_NOT_FOUND' || msg === 'INVALID_PRODUCT_ID') {
        return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Producto inválido.' } };
      }
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async addPayment(input: {
    empresaId: number;
    createdBy: number | null;
    invoiceId: number;
    paymentAmount: number;
    paymentMethod: string;
    paymentDate?: string;
    referenceNumber?: string;
    notes?: string;
  }): Promise<ApiResponse<{ done: true }>> {
    const invoiceId = Number(input.invoiceId);
    if (!Number.isFinite(invoiceId) || invoiceId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Factura inválida' } };
    }
    const amount = asMoney(input.paymentAmount);
    if (amount <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Monto inválido' } };
    }
    const method = (input.paymentMethod ?? '').trim();
    if (!method) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Método de pago obligatorio' } };
    }
    const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const paymentDate = (input.paymentDate ?? '').trim() || now;
    try {
      const ok = await this.invoicesDao.addPayment({
        empresaId: input.empresaId,
        invoiceId,
        payment: {
          paymentAmount: amount,
          paymentMethod: method,
          paymentDate,
          referenceNumber: (input.referenceNumber ?? '').trim() || null,
          notes: (input.notes ?? '').trim() || null,
          createdBy: input.createdBy,
        },
      });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'No se pudo registrar el pago' } };
      return { ok: true, data: { done: true } };
    } catch (e) {
      if (String((e as { message?: string })?.message ?? '') === 'NO_OPEN_CASH') {
        return { ok: false, error: { code: 'NO_OPEN_SESSION', message: 'No hay una sesión de caja abierta.' } };
      }
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async cancelInvoice(input: { empresaId: number; invoiceId: number; reason: string; cancelledBy: number | null }) {
    const invoiceId = Number(input.invoiceId);
    if (!Number.isFinite(invoiceId) || invoiceId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Factura inválida' } };
    }
    const reason = input.reason.trim();
    if (!reason) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Debe indicar el motivo' } };
    }
    try {
      const ok = await this.invoicesDao.cancel({
        empresaId: input.empresaId,
        invoiceId,
        reason,
        cancelledBy: input.cancelledBy,
      });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'No se pudo anular la factura' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}
