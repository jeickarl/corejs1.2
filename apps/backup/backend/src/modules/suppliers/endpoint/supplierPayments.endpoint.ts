import { Body, Controller, Get, Param, Patch, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { SupplierPaymentsController } from '../controller/supplierPayments.controller';
import { SupplierPaymentsPageDto } from '../modelo/supplierPaymentsPage.dto';
import { PendingPurchaseOrdersPageDto } from '../modelo/pendingPurchaseOrdersPage.dto';
import { CreateSupplierPaymentDto } from '../modelo/createSupplierPayment.dto';
import { VoidSupplierPaymentDto } from '../modelo/voidSupplierPayment.dto';

@ApiTags('supplier-payments')
@Controller('supplier-payments')
@UseGuards(RolesGuard)
@Roles('ADMIN', 'USER')
export class SupplierPaymentsEndpoint {
  constructor(private readonly supplierPaymentsController: SupplierPaymentsController) {}

  @Get('recent')
  @ApiOkResponse({ type: SupplierPaymentsPageDto })
  recent(
    @Req() req: Request & { user?: unknown },
    @Query('page') page = '1',
    @Query('perPage') perPage = '20',
    @Query('includeVoided') includeVoided = '',
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    const inc = includeVoided.trim();
    return this.supplierPaymentsController.recent({
      empresaId: tenantId,
      page: Number(page),
      perPage: Number(perPage),
      includeVoided: inc === '1' || inc.toLowerCase() === 'true',
    });
  }

  @Get('voided')
  @ApiOkResponse({ type: SupplierPaymentsPageDto })
  voided(@Req() req: Request & { user?: unknown }, @Query('page') page = '1', @Query('perPage') perPage = '20') {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.supplierPaymentsController.voided({ empresaId: tenantId, page: Number(page), perPage: Number(perPage) });
  }

  @Get('pending-orders')
  @ApiOkResponse({ type: PendingPurchaseOrdersPageDto })
  pendingOrders(@Req() req: Request & { user?: unknown }, @Query('page') page = '1', @Query('perPage') perPage = '20') {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.supplierPaymentsController.pendingOrders({ empresaId: tenantId, page: Number(page), perPage: Number(perPage) });
  }

  @Post()
  create(@Req() req: Request & { user?: unknown }, @Body() body: CreateSupplierPaymentDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.supplierPaymentsController.createPayment({
      empresaId: tenantId,
      supplierId: Number(body.supplierId),
      purchaseOrderId: body.purchaseOrderId === undefined ? undefined : Number(body.purchaseOrderId),
      paymentAmount: Number(body.paymentAmount),
      paymentMethod: body.paymentMethod,
      paymentDate: body.paymentDate,
      referenceNumber: body.referenceNumber,
      notes: body.notes,
      createdBy: user.sub ?? null,
      requestId: body.requestId,
    });
  }

  @Patch(':id/void')
  voidPayment(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: VoidSupplierPaymentDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.supplierPaymentsController.voidPayment({
      empresaId: tenantId,
      id: Number(id),
      reason: body.reason,
      voidedBy: user.sub ?? null,
    });
  }
}

