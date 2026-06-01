import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import {
  type DashboardAnalyticsRow,
  type DashboardOrdersChartRow,
  type DashboardSalesChartRow,
  type DashboardSearchItemRow,
  type DashboardSummaryRow,
  DashboardDao,
} from '../daos/dashboard.dao';

function nowHhMmSs(): string {
  const d = new Date();
  const hh = String(d.getHours()).padStart(2, '0');
  const mm = String(d.getMinutes()).padStart(2, '0');
  const ss = String(d.getSeconds()).padStart(2, '0');
  return `${hh}:${mm}:${ss}`;
}

@Injectable()
export class DashboardController {
  constructor(private readonly dashboardDao: DashboardDao) {}

  async summary(input: {
    empresaId: number;
    revenuePeriod: 'day' | 'week' | 'month' | 'year' | 'total';
  }): Promise<ApiResponse<DashboardSummaryRow>> {
    try {
      const data = await this.dashboardDao.summary(input);
      return { ok: true, data };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async salesChart(input: { empresaId: number; days: number }): Promise<ApiResponse<DashboardSalesChartRow>> {
    try {
      const data = await this.dashboardDao.salesChart(input);
      return { ok: true, data };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async ordersChart(input: { empresaId: number }): Promise<ApiResponse<DashboardOrdersChartRow>> {
    try {
      const data = await this.dashboardDao.ordersChart(input);
      return { ok: true, data };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async analytics(input: { empresaId: number; from: string; to: string }): Promise<ApiResponse<DashboardAnalyticsRow>> {
    try {
      const data = await this.dashboardDao.analytics(input);
      return { ok: true, data };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async getNotes(input: { empresaId: number; userId: number }): Promise<ApiResponse<{ content: string }>> {
    try {
      const note = await this.dashboardDao.getNotes(input);
      return { ok: true, data: { content: note.content } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async saveNotes(input: { empresaId: number; userId: number; content: string }): Promise<ApiResponse<{ done: true; timestamp: string }>> {
    try {
      const ok = await this.dashboardDao.saveNotes(input);
      if (!ok) {
        return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
      }
      return { ok: true, data: { done: true, timestamp: nowHhMmSs() } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async search(input: {
    empresaId: number;
    query: string;
    type: 'orders' | 'clients' | 'inventory';
  }): Promise<ApiResponse<DashboardSearchItemRow[]>> {
    try {
      const data = await this.dashboardDao.globalSearch(input);
      return { ok: true, data };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}
