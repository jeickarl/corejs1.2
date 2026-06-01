import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { AccessoriesDao } from '../daos/accessories.dao';
import { OrdersDao, type OrderListRow, type OrderRow } from '../daos/orders.dao';

function dbErrorMessage(e: unknown): string {
  if (e instanceof Error) {
    const m = e.message.trim();
    if (m.startsWith('TENANT_DB_CONNECT_FAILED')) return m;
  }
  return 'Error de base de datos';
}

@Injectable()
export class OrdersController {
  constructor(
    private readonly ordersDao: OrdersDao,
    private readonly accessoriesDao: AccessoriesDao,
  ) {}

  async accessories(input: { empresaId: number }): Promise<ApiResponse<{ id: number; name: string }[]>> {
    try {
      const rows = await this.accessoriesDao.list(input.empresaId);
      return { ok: true, data: rows };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async createAccessory(input: { empresaId: number; name: string }): Promise<ApiResponse<{ id: number; name: string }>> {
    const name = input.name.trim();
    if (!name) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'El nombre del accesorio es obligatorio' } };
    }
    try {
      const row = await this.accessoriesDao.create({ empresaId: input.empresaId, name });
      if (!row) {
        return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
      }
      return { ok: true, data: row };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async statuses(input: { empresaId: number }): Promise<ApiResponse<unknown[]>> {
    try {
      const rows = await this.ordersDao.listStatuses({ empresaId: input.empresaId });
      return { ok: true, data: rows };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async serialLookup(input: {
    empresaId: number;
    serial: string;
  }): Promise<ApiResponse<{ orderId: number; clientId: number; deviceTypeId: number; deviceBrand: string; deviceModel: string }>> {
    try {
      const row = await this.ordersDao.serialLookup({ empresaId: input.empresaId, serial: input.serial });
      if (!row) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Sin coincidencias' } };
      }
      return { ok: true, data: row };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async list(input: {
    empresaId: number;
    search: string;
    status: string;
    approvalStatus: string;
    clientId: number | null;
    page: number;
    perPage: number;
  }): Promise<ApiResponse<{ items: OrderListRow[]; page: number; perPage: number; total: number }>> {
    const page = Number.isFinite(input.page) && input.page > 0 ? input.page : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? input.perPage : 10;
    const offset = (page - 1) * perPage;
    try {
      const { rows, total } = await this.ordersDao.list({
        empresaId: input.empresaId,
        search: input.search,
        status: input.status,
        approvalStatus: input.approvalStatus,
        clientId: input.clientId,
        limit: perPage,
        offset,
      });
      return { ok: true, data: { items: rows, page, perPage, total } };
    } catch (e) {
      return { ok: false, error: { code: 'DB_ERROR', message: dbErrorMessage(e) } };
    }
  }

  async clientStats(input: {
    empresaId: number;
    clientId: number;
  }): Promise<ApiResponse<{ total: number; pending: number; inProcess: number; completed: number }>> {
    const clientId = Number(input.clientId);
    if (!Number.isFinite(clientId) || clientId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'clientId inválido' } };
    }
    try {
      const stats = await this.ordersDao.statsByClientId({ empresaId: input.empresaId, clientId });
      return { ok: true, data: stats };
    } catch (e) {
      return { ok: false, error: { code: 'DB_ERROR', message: dbErrorMessage(e) } };
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<ApiResponse<OrderRow>> {
    try {
      const row = await this.ordersDao.getById(input);
      if (!row) return { ok: false, error: { code: 'NOT_FOUND', message: 'Orden no encontrada' } };
      return { ok: true, data: row };
    } catch (e) {
      return { ok: false, error: { code: 'DB_ERROR', message: dbErrorMessage(e) } };
    }
  }

  async create(input: {
    empresaId: number;
    clientId: number;
    deviceTypeId: number;
    deviceBrand?: string;
    deviceModel?: string;
    devicePassword?: string;
    serialNumber: string;
    reportedIssue: string;
    clientObservations?: string;
    status?: string;
    priority?: string;
    estimatedCost?: number;
    finalCost?: number;
    advancePayment?: number;
    paymentMethod?: string;
    paymentReference?: string;
    technicianNotes?: string;
    estimatedCompletion?: string | null;
    accessoryIds?: number[];
  }): Promise<ApiResponse<OrderRow>> {
    const serialNumber = input.serialNumber.trim();
    const reportedIssue = input.reportedIssue.trim();
    if (!serialNumber) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'El serial es obligatorio.' } };
    }
    if (!reportedIssue) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'La descripción del problema es obligatoria.' } };
    }

    const adv = Number(input.advancePayment ?? 0) || 0;
    const pm = (input.paymentMethod ?? '').trim();
    if (adv > 0 && !pm) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'El método de pago es obligatorio si hay abono.' } };
    }

    try {
      const st = (input.status ?? '').trim() || 'pending';
      const id = await this.ordersDao.create({
        empresaId: input.empresaId,
        clientId: input.clientId,
        deviceTypeId: input.deviceTypeId,
        deviceBrand: (input.deviceBrand ?? '').trim(),
        deviceModel: (input.deviceModel ?? '').trim(),
        devicePassword: (input.devicePassword ?? '').trim(),
        serialNumber,
        reportedIssue,
        clientObservations: (input.clientObservations ?? '').trim(),
        status: st,
        priority: (input.priority ?? 'medium').trim() || 'medium',
        estimatedCost: Number(input.estimatedCost ?? 0) || 0,
        advancePayment: adv,
        paymentMethod: pm,
        paymentReference: (input.paymentReference ?? '').trim(),
        technicianNotes: (input.technicianNotes ?? '').trim(),
        estimatedCompletion: input.estimatedCompletion ?? null,
      });
      try {
        await this.accessoriesDao.setIncluded({
          empresaId: input.empresaId,
          orderId: id,
          accessoryIds: input.accessoryIds ?? [],
        });
      } catch {
      }
      const created = await this.ordersDao.getById({ empresaId: input.empresaId, id });
      if (!created) return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
      return { ok: true, data: created };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async update(input: {
    empresaId: number;
    id: number;
    clientId: number;
    deviceTypeId: number;
    deviceBrand?: string;
    deviceModel?: string;
    devicePassword?: string;
    serialNumber: string;
    reportedIssue: string;
    clientObservations?: string;
    priority?: string;
    estimatedCost?: number;
    finalCost?: number;
    advancePayment?: number;
    paymentMethod?: string;
    paymentReference?: string;
    technicianNotes?: string;
    diagnosis?: string;
    solution?: string;
    estimatedCompletion?: string | null;
    accessoryIds?: number[];
  }): Promise<ApiResponse<OrderRow>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }

    const serialNumber = input.serialNumber.trim();
    const reportedIssue = input.reportedIssue.trim();
    if (!serialNumber) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'El serial es obligatorio.' } };
    }
    if (!reportedIssue) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'La descripción del problema es obligatoria.' } };
    }

    const adv = Number(input.advancePayment ?? 0) || 0;
    const pm = (input.paymentMethod ?? '').trim();
    if (adv > 0 && !pm) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'El método de pago es obligatorio si hay abono.' } };
    }

    try {
      const existing = await this.ordersDao.getById({ empresaId: input.empresaId, id });
      if (!existing) return { ok: false, error: { code: 'NOT_FOUND', message: 'Orden no encontrada' } };

      const ok = await this.ordersDao.update({
        empresaId: input.empresaId,
        id,
        clientId: input.clientId,
        deviceTypeId: input.deviceTypeId,
        deviceBrand: (input.deviceBrand ?? '').trim(),
        deviceModel: (input.deviceModel ?? '').trim(),
        devicePassword: (input.devicePassword ?? '').trim(),
        serialNumber,
        reportedIssue,
        clientObservations: (input.clientObservations ?? '').trim(),
        priority: (input.priority ?? 'medium').trim() || 'medium',
        estimatedCost: Number(input.estimatedCost ?? 0) || 0,
        finalCost: input.finalCost === undefined || input.finalCost === null ? null : Number(input.finalCost),
        advancePayment: adv,
        paymentMethod: pm,
        paymentReference: (input.paymentReference ?? '').trim(),
        technicianNotes: (input.technicianNotes ?? '').trim(),
        diagnosis: (input.diagnosis ?? '').trim(),
        solution: (input.solution ?? '').trim(),
        estimatedCompletion: input.estimatedCompletion ?? null,
      });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'Orden no encontrada' } };
      try {
        await this.accessoriesDao.setIncluded({
          empresaId: input.empresaId,
          orderId: id,
          accessoryIds: input.accessoryIds ?? [],
        });
      } catch {
      }

      const updated = await this.ordersDao.getById({ empresaId: input.empresaId, id });
      if (!updated) return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
      return { ok: true, data: updated };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async changeStatus(input: {
    empresaId: number;
    id: number;
    status: string;
    userId: number | null;
    finalCost?: number;
  }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    const status = input.status.trim();
    if (!status) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Estado requerido' } };
    }
    try {
      const ok = await this.ordersDao.setStatus({
        empresaId: input.empresaId,
        id,
        status,
        userId: input.userId,
        finalCost: input.finalCost ?? null,
      });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'Orden no encontrada' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async delete(input: { empresaId: number; id: number }): Promise<ApiResponse<{ deleted: true }>> {
    try {
      const ok = await this.ordersDao.delete(input);
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'Orden no encontrada' } };
      return { ok: true, data: { deleted: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async changeApproval(input: {
    empresaId: number;
    id: number;
    approvalStatus: 'none' | 'pending' | 'approved' | 'rejected';
    approvedQuoteAmount?: number | null;
    approvalComment?: string | null;
    approvalSignature?: string | null;
  }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    try {
      const ok = await this.ordersDao.setApproval({
        empresaId: input.empresaId,
        id,
        approvalStatus: input.approvalStatus,
        approvedQuoteAmount: input.approvedQuoteAmount ?? null,
        approvalComment: input.approvalComment ?? null,
        approvalSignature: input.approvalSignature ?? null,
      });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'Orden no encontrada' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async history(input: { empresaId: number; id: number }): Promise<ApiResponse<unknown[]>> {
    try {
      const rows = await this.ordersDao.history(input);
      return { ok: true, data: rows };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async listTechnicalReports(input: { empresaId: number; orderId: number }): Promise<ApiResponse<unknown[]>> {
    const orderId = Number(input.orderId);
    if (!Number.isFinite(orderId) || orderId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    try {
      const rows = await this.ordersDao.listTechnicalReports({ empresaId: input.empresaId, orderId });
      return { ok: true, data: rows };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async getTechnicalReport(input: { empresaId: number; orderId: number; reportId: number }): Promise<ApiResponse<unknown>> {
    const orderId = Number(input.orderId);
    const reportId = Number(input.reportId);
    if (!Number.isFinite(orderId) || orderId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    if (!Number.isFinite(reportId) || reportId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    try {
      const row = await this.ordersDao.getTechnicalReport({ empresaId: input.empresaId, orderId, reportId });
      if (!row) return { ok: false, error: { code: 'NOT_FOUND', message: 'Informe no encontrado' } };
      return { ok: true, data: row };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async createTechnicalReport(input: {
    empresaId: number;
    orderId: number;
    reportTitle: string;
    diagnosis: string;
    procedureTaken: string;
    introduction: string;
    conclusion: string;
    createdBy: number | null;
  }): Promise<ApiResponse<{ id: number }>> {
    const orderId = Number(input.orderId);
    if (!Number.isFinite(orderId) || orderId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    const title = input.reportTitle.trim();
    if (!title) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Título requerido' } };
    }
    try {
      const id = await this.ordersDao.createTechnicalReport({
        empresaId: input.empresaId,
        orderId,
        reportTitle: title,
        diagnosis: (input.diagnosis ?? '').trim(),
        procedureTaken: (input.procedureTaken ?? '').trim(),
        introduction: (input.introduction ?? '').trim(),
        conclusion: (input.conclusion ?? '').trim(),
        photosJson: null,
        createdBy: input.createdBy,
      });
      if (!id) return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
      return { ok: true, data: { id } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async deleteTechnicalReport(input: {
    empresaId: number;
    orderId: number;
    reportId: number;
  }): Promise<ApiResponse<{ deleted: true }>> {
    const orderId = Number(input.orderId);
    const reportId = Number(input.reportId);
    if (!Number.isFinite(orderId) || orderId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    if (!Number.isFinite(reportId) || reportId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    try {
      const ok = await this.ordersDao.deleteTechnicalReport({ empresaId: input.empresaId, orderId, reportId });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'Informe no encontrado' } };
      return { ok: true, data: { deleted: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async listOrderServices(input: { empresaId: number; orderId: number }): Promise<ApiResponse<unknown[]>> {
    const orderId = Number(input.orderId);
    if (!Number.isFinite(orderId) || orderId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    try {
      const rows = await this.ordersDao.listOrderServices({ empresaId: input.empresaId, orderId });
      return { ok: true, data: rows };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async addOrderService(input: {
    empresaId: number;
    orderId: number;
    serviceId: number;
    quantity: number;
    servicePrice: number;
  }): Promise<ApiResponse<{ id: number }>> {
    const orderId = Number(input.orderId);
    const serviceId = Number(input.serviceId);
    const quantity = Number(input.quantity);
    const servicePrice = Number(input.servicePrice);
    if (!Number.isFinite(orderId) || orderId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    if (!Number.isFinite(serviceId) || serviceId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Servicio inválido' } };
    }
    if (!Number.isFinite(quantity) || quantity <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Cantidad inválida' } };
    }
    if (!Number.isFinite(servicePrice) || servicePrice < 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Precio inválido' } };
    }
    try {
      const id = await this.ordersDao.addOrderService({ empresaId: input.empresaId, orderId, serviceId, quantity, servicePrice });
      if (!id) return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
      return { ok: true, data: { id } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async deleteOrderService(input: {
    empresaId: number;
    orderId: number;
    itemId: number;
  }): Promise<ApiResponse<{ deleted: true }>> {
    const orderId = Number(input.orderId);
    const itemId = Number(input.itemId);
    if (!Number.isFinite(orderId) || orderId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    if (!Number.isFinite(itemId) || itemId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    try {
      const ok = await this.ordersDao.deleteOrderService({ empresaId: input.empresaId, orderId, itemId });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      return { ok: true, data: { deleted: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}
