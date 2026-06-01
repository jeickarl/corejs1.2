import { Body, Controller, Get, Param, Patch, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { SalesController } from '../controller/sales.controller';
import { CreateInvoiceDto } from '../modelo/createInvoice.dto';
import { InvoiceDto } from '../modelo/invoice.dto';
import { InvoicesPageDto } from '../modelo/invoicesPage.dto';
import { AddPaymentDto } from '../modelo/addPayment.dto';
import { CancelInvoiceDto } from '../modelo/cancelInvoice.dto';

@ApiTags('sales')
@Controller('sales')
@UseGuards(RolesGuard)
@Roles('ADMIN', 'USER')
export class SalesEndpoint {
  constructor(private readonly salesController: SalesController) {}

  @Get('invoices')
  @ApiOkResponse({ type: InvoicesPageDto })
  listInvoices(
    @Req() req: Request & { user?: unknown },
    @Query('search') search = '',
    @Query('status') status = '',
    @Query('paymentStatus') paymentStatus = '',
    @Query('page') page = '1',
    @Query('perPage') perPage = '10',
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.salesController.listInvoices({
      empresaId: tenantId,
      search,
      status,
      paymentStatus,
      page: Number(page),
      perPage: Number(perPage),
    });
  }

  @Get('invoices/:id')
  @ApiOkResponse({ type: InvoiceDto })
  getInvoice(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.salesController.getInvoice({ empresaId: tenantId, id: Number(id) });
  }

  @Post('invoices')
  createInvoice(@Req() req: Request & { user?: unknown }, @Body() body: CreateInvoiceDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.salesController.createInvoice({
      empresaId: tenantId,
      createdBy: user.sub ?? null,
      clientId: body.clientId,
      documentType: body.documentType,
      invoiceDate: body.invoiceDate,
      dueDate: body.dueDate ?? null,
      notes: body.notes,
      termsConditions: body.termsConditions,
      action: body.action,
      items: body.items,
      payments: body.payments ?? [],
    });
  }

  @Post('invoices/:id/payments')
  addPayment(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: AddPaymentDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.salesController.addPayment({
      empresaId: tenantId,
      createdBy: user.sub ?? null,
      invoiceId: Number(id),
      paymentAmount: body.paymentAmount,
      paymentMethod: body.paymentMethod,
      paymentDate: body.paymentDate,
      referenceNumber: body.referenceNumber,
      notes: body.notes,
    });
  }

  @Patch('invoices/:id/cancel')
  cancel(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: CancelInvoiceDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.salesController.cancelInvoice({
      empresaId: tenantId,
      invoiceId: Number(id),
      reason: body.reason,
      cancelledBy: user.sub ?? null,
    });
  }
}

