import { Body, Controller, Get, Param, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { PurchaseReceiptsController } from '../controller/purchaseReceipts.controller';
import { PurchaseReceiptDto } from '../modelo/purchaseReceipt.dto';
import { PurchaseReceiptsPageDto } from '../modelo/purchaseReceiptsPage.dto';
import { CreatePurchaseReceiptDto } from '../modelo/createPurchaseReceipt.dto';

@ApiTags('purchase-receipts')
@Controller('purchase-receipts')
@UseGuards(RolesGuard)
@Roles('ADMIN', 'USER')
export class PurchaseReceiptsEndpoint {
  constructor(private readonly purchaseReceiptsController: PurchaseReceiptsController) {}

  @Get()
  @ApiOkResponse({ type: PurchaseReceiptsPageDto })
  list(
    @Req() req: Request & { user?: unknown },
    @Query('search') search = '',
    @Query('page') page = '1',
    @Query('perPage') perPage = '10',
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.purchaseReceiptsController.list({ empresaId: tenantId, search, page: Number(page), perPage: Number(perPage) });
  }

  @Get(':id')
  @ApiOkResponse({ type: PurchaseReceiptDto })
  get(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.purchaseReceiptsController.getById({ empresaId: tenantId, id: Number(id) });
  }

  @Post()
  create(@Req() req: Request & { user?: unknown }, @Body() body: CreatePurchaseReceiptDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.purchaseReceiptsController.create({
      empresaId: tenantId,
      purchaseOrderId: Number(body.purchaseOrderId),
      receivedDate: body.receivedDate,
      notes: body.notes,
      createdBy: user.sub ?? null,
      items: body.items,
    });
  }
}

