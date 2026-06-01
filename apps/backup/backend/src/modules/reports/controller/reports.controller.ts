import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { ReportsDao } from '../daos/reports.dao';

@Injectable()
export class ReportsController {
  constructor(private readonly reportsDao: ReportsDao) {}

  async billingReport(input: {
    empresaId: number;
    from: string;
    to: string;
    status: string;
    paymentStatus: string;
    page: number;
    perPage: number;
  }): Promise<
    ApiResponse<{
      items: Awaited<ReturnType<ReportsDao['billingReport']>>['rows'];
      page: number;
      perPage: number;
      total: number;
      totals: Awaited<ReturnType<ReportsDao['billingReport']>>['totals'];
    }>
  > {
    const page = Number.isFinite(input.page) && input.page > 0 ? Math.floor(input.page) : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? Math.floor(input.perPage) : 10;
    const limit = Math.min(200, perPage);
    const offset = (page - 1) * limit;
    try {
      const res = await this.reportsDao.billingReport({
        empresaId: input.empresaId,
        from: input.from,
        to: input.to,
        status: input.status,
        paymentStatus: input.paymentStatus,
        limit,
        offset,
      });
      return { ok: true, data: { items: res.rows, page, perPage: limit, total: res.total, totals: res.totals } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async suppliersReport(input: { empresaId: number; from: string; to: string }): Promise<ApiResponse<{ items: Awaited<ReturnType<ReportsDao['suppliersReport']>>['rows'] }>> {
    try {
      const res = await this.reportsDao.suppliersReport({ empresaId: input.empresaId, from: input.from, to: input.to });
      return { ok: true, data: { items: res.rows } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async servicesReport(input: {
    empresaId: number;
    from: string;
    to: string;
    serviceId: number | null;
    categoryId: number | null;
  }): Promise<ApiResponse<{ stats: Awaited<ReturnType<ReportsDao['servicesReport']>>['stats']; items: Awaited<ReturnType<ReportsDao['servicesReport']>>['rows'] }>> {
    try {
      const res = await this.reportsDao.servicesReport({
        empresaId: input.empresaId,
        from: input.from,
        to: input.to,
        serviceId: input.serviceId,
        categoryId: input.categoryId,
      });
      return { ok: true, data: { stats: res.stats, items: res.rows } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}
