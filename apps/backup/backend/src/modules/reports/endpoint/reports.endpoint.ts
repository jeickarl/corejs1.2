import { Controller, Get, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import { ReportsController } from '../controller/reports.controller';
import { BillingReportPageDto } from '../modelo/billingReportPage.dto';
import { SuppliersReportDto } from '../modelo/suppliersReport.dto';
import { ServicesReportDto } from '../modelo/servicesReport.dto';

@ApiTags('reports')
@Controller('reports')
@UseGuards(RolesGuard)
@Roles('ADMIN')
export class ReportsEndpoint {
  constructor(private readonly reportsController: ReportsController) {}

  @Get('billing')
  @ApiOkResponse({ type: BillingReportPageDto })
  billing(
    @Req() req: Request & { user?: unknown },
    @Query('from') from = '',
    @Query('to') to = '',
    @Query('status') status = '',
    @Query('paymentStatus') paymentStatus = '',
    @Query('page') page = '1',
    @Query('perPage') perPage = '20',
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.reportsController.billingReport({
      empresaId: tenantId,
      from,
      to,
      status,
      paymentStatus,
      page: Number(page),
      perPage: Number(perPage),
    });
  }

  @Get('suppliers')
  @ApiOkResponse({ type: SuppliersReportDto })
  suppliers(@Req() req: Request & { user?: unknown }, @Query('from') from = '', @Query('to') to = '') {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.reportsController.suppliersReport({ empresaId: tenantId, from, to });
  }

  @Get('services')
  @ApiOkResponse({ type: ServicesReportDto })
  services(
    @Req() req: Request & { user?: unknown },
    @Query('from') from = '',
    @Query('to') to = '',
    @Query('serviceId') serviceId = '',
    @Query('categoryId') categoryId = '',
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.reportsController.servicesReport({
      empresaId: tenantId,
      from,
      to,
      serviceId: serviceId.trim() ? Number(serviceId) : null,
      categoryId: categoryId.trim() ? Number(categoryId) : null,
    });
  }
}
