import { Body, Controller, Get, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import { DashboardController } from '../controller/dashboard.controller';
import { DashboardNotesDto, DashboardNotesSavedDto, DashboardNotesUpdateDto } from '../modelo/dashboardNotes.dto';
import { DashboardOrdersChartDto } from '../modelo/dashboardOrdersChart.dto';
import { DashboardSalesChartDto } from '../modelo/dashboardSalesChart.dto';
import { DashboardSearchItemDto } from '../modelo/dashboardSearch.dto';
import { DashboardSummaryDto } from '../modelo/dashboardSummary.dto';
import { DashboardAnalyticsDto } from '../modelo/dashboardAnalytics.dto';

@ApiTags('dashboard')
@Controller('dashboard')
@UseGuards(RolesGuard)
@Roles('ADMIN', 'USER')
export class DashboardEndpoint {
  constructor(private readonly dashboardController: DashboardController) {}

  private tenantFromReq(req: Request & { user?: unknown }) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false as const, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return { ok: true as const, tenantId, userId: user.sub };
  }

  @Get('summary')
  @ApiOkResponse({ type: DashboardSummaryDto })
  summary(
    @Req() req: Request & { user?: unknown },
    @Query('revenuePeriod') revenuePeriod = 'month',
  ) {
    const t = this.tenantFromReq(req);
    if (!t.ok) return t;
    const rp =
      revenuePeriod === 'day' ||
      revenuePeriod === 'week' ||
      revenuePeriod === 'month' ||
      revenuePeriod === 'year' ||
      revenuePeriod === 'total'
        ? revenuePeriod
        : 'month';
    return this.dashboardController.summary({ empresaId: t.tenantId, revenuePeriod: rp });
  }

  @Get('sales-chart')
  @ApiOkResponse({ type: DashboardSalesChartDto })
  salesChart(@Req() req: Request & { user?: unknown }, @Query('days') days = '7') {
    const t = this.tenantFromReq(req);
    if (!t.ok) return t;
    return this.dashboardController.salesChart({ empresaId: t.tenantId, days: Number(days) });
  }

  @Get('orders-chart')
  @ApiOkResponse({ type: DashboardOrdersChartDto })
  ordersChart(@Req() req: Request & { user?: unknown }) {
    const t = this.tenantFromReq(req);
    if (!t.ok) return t;
    return this.dashboardController.ordersChart({ empresaId: t.tenantId });
  }

  @Get('analytics')
  @ApiOkResponse({ type: DashboardAnalyticsDto })
  analytics(@Req() req: Request & { user?: unknown }, @Query('from') from = '', @Query('to') to = '') {
    const t = this.tenantFromReq(req);
    if (!t.ok) return t;
    const today = new Date();
    const toIso = to.trim() || today.toISOString().slice(0, 10);
    const fromIso = from.trim() || new Date(today.getFullYear(), today.getMonth(), today.getDate() - 30).toISOString().slice(0, 10);
    return this.dashboardController.analytics({ empresaId: t.tenantId, from: fromIso, to: toIso });
  }

  @Get('notes')
  @ApiOkResponse({ type: DashboardNotesDto })
  notes(@Req() req: Request & { user?: unknown }) {
    const t = this.tenantFromReq(req);
    if (!t.ok) return t;
    return this.dashboardController.getNotes({ empresaId: t.tenantId, userId: t.userId });
  }

  @Post('notes')
  @ApiOkResponse({ type: DashboardNotesSavedDto })
  saveNotes(@Req() req: Request & { user?: unknown }, @Body() body: DashboardNotesUpdateDto) {
    const t = this.tenantFromReq(req);
    if (!t.ok) return t;
    return this.dashboardController.saveNotes({
      empresaId: t.tenantId,
      userId: t.userId,
      content: (body.content ?? '').toString(),
    });
  }

  @Get('search')
  @ApiOkResponse({ type: [DashboardSearchItemDto] })
  search(
    @Req() req: Request & { user?: unknown },
    @Query('query') query = '',
    @Query('type') type = 'orders',
  ) {
    const t = this.tenantFromReq(req);
    if (!t.ok) return t;
    const tp = type === 'orders' || type === 'clients' || type === 'inventory' ? type : 'orders';
    return this.dashboardController.search({ empresaId: t.tenantId, query, type: tp });
  }
}
