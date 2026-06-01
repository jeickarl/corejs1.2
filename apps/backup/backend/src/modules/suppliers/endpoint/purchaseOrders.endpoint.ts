import { Body, Controller, Delete, Get, Param, Patch, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { PurchaseOrdersController } from '../controller/purchaseOrders.controller';
import { PurchaseOrderDto } from '../modelo/purchaseOrder.dto';
import { PurchaseOrdersPageDto } from '../modelo/purchaseOrdersPage.dto';
import { CreatePurchaseOrderDto } from '../modelo/createPurchaseOrder.dto';
import { UpdatePurchaseOrderDto } from '../modelo/updatePurchaseOrder.dto';

@ApiTags('purchase-orders')
@Controller('purchase-orders')
@UseGuards(RolesGuard)
@Roles('ADMIN', 'USER')
export class PurchaseOrdersEndpoint {
  constructor(private readonly purchaseOrdersController: PurchaseOrdersController) {}

  @Get()
  @ApiOkResponse({ type: PurchaseOrdersPageDto })
  list(
    @Req() req: Request & { user?: unknown },
    @Query('search') search = '',
    @Query('supplierId') supplierId = '',
    @Query('status') status = '',
    @Query('page') page = '1',
    @Query('perPage') perPage = '10',
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.purchaseOrdersController.list({
      empresaId: tenantId,
      search,
      supplierId: supplierId.trim() ? Number(supplierId) : undefined,
      status: status.trim() ? status.trim() : undefined,
      page: Number(page),
      perPage: Number(perPage),
    });
  }

  @Get(':id')
  @ApiOkResponse({ type: PurchaseOrderDto })
  get(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.purchaseOrdersController.getById({ empresaId: tenantId, id: Number(id) });
  }

  @Post()
  @ApiOkResponse({ type: PurchaseOrderDto })
  create(@Req() req: Request & { user?: unknown }, @Body() body: CreatePurchaseOrderDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.purchaseOrdersController.create({
      empresaId: tenantId,
      supplierId: Number(body.supplierId),
      orderDate: body.orderDate,
      expectedDate: body.expectedDate,
      paymentMethod: body.paymentMethod,
      paymentTerms: body.paymentTerms,
      notes: body.notes,
      createdByUserId: user.sub ?? null,
    });
  }

  @Patch(':id')
  update(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: UpdatePurchaseOrderDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.purchaseOrdersController.update({
      empresaId: tenantId,
      id: Number(id),
      supplierId: Number(body.supplierId),
      orderDate: body.orderDate,
      expectedDate: body.expectedDate,
      paymentMethod: body.paymentMethod,
      paymentTerms: body.paymentTerms,
      notes: body.notes,
      status: body.status,
    });
  }

  @Delete(':id')
  cancel(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.purchaseOrdersController.cancel({ empresaId: tenantId, id: Number(id) });
  }
}

