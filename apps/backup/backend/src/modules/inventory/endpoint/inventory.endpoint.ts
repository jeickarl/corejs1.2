import { Body, Controller, Delete, Get, Param, Patch, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { InventoryController } from '../controller/inventory.controller';
import { InventoryProductDto } from '../modelo/inventoryProduct.dto';
import { InventoryProductsPageDto } from '../modelo/inventoryProductsPage.dto';
import { CreateInventoryProductDto } from '../modelo/createInventoryProduct.dto';
import { UpdateInventoryProductDto } from '../modelo/updateInventoryProduct.dto';
import { CreateInventoryMovementDto } from '../modelo/createInventoryMovement.dto';
import { InventoryMovementsPageDto } from '../modelo/inventoryMovementsPage.dto';

@ApiTags('inventory')
@Controller('inventory')
@UseGuards(RolesGuard)
@Roles('ADMIN', 'USER')
export class InventoryEndpoint {
  constructor(private readonly inventoryController: InventoryController) {}

  @Get('products')
  @ApiOkResponse({ type: InventoryProductsPageDto })
  listProducts(
    @Req() req: Request & { user?: unknown },
    @Query('search') search = '',
    @Query('onlyActive') onlyActive = '1',
    @Query('page') page = '1',
    @Query('perPage') perPage = '10',
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.inventoryController.listProducts({
      empresaId: tenantId,
      search,
      onlyActive: String(onlyActive) !== '0',
      page: Number(page),
      perPage: Number(perPage),
    });
  }

  @Get('products/:id')
  @ApiOkResponse({ type: InventoryProductDto })
  getProduct(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.inventoryController.getProduct({ empresaId: tenantId, id: Number(id) });
  }

  @Post('products')
  createProduct(@Req() req: Request & { user?: unknown }, @Body() body: CreateInventoryProductDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.inventoryController.createProduct({
      empresaId: tenantId,
      sku: body.sku ?? null,
      name: body.name,
      description: body.description ?? null,
      salePrice: body.salePrice ?? 0,
      costPrice: body.costPrice ?? 0,
      currentStock: body.currentStock ?? 0,
      minStock: body.minStock ?? 0,
      isActive: body.isActive ?? true,
    });
  }

  @Patch('products/:id')
  updateProduct(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: UpdateInventoryProductDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.inventoryController.updateProduct({
      empresaId: tenantId,
      id: Number(id),
      sku: body.sku ?? null,
      name: body.name ?? '',
      description: body.description ?? null,
      salePrice: body.salePrice ?? 0,
      costPrice: body.costPrice ?? 0,
      minStock: body.minStock ?? 0,
      isActive: body.isActive ?? true,
    });
  }

  @Delete('products/:id')
  deleteProduct(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.inventoryController.deleteProduct({ empresaId: tenantId, id: Number(id) });
  }

  @Post('movements')
  createMovement(@Req() req: Request & { user?: unknown }, @Body() body: CreateInventoryMovementDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.inventoryController.adjustStock({
      empresaId: tenantId,
      productId: body.productId,
      movementType: body.movementType,
      quantity: body.quantity,
      notes: body.notes ?? null,
      createdBy: user.sub ?? null,
      referenceType: 'manual',
      referenceId: null,
    });
  }

  @Get('products/:id/movements')
  @ApiOkResponse({ type: InventoryMovementsPageDto })
  listMovements(
    @Req() req: Request & { user?: unknown },
    @Param('id') id: string,
    @Query('page') page = '1',
    @Query('perPage') perPage = '10',
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.inventoryController.listMovements({
      empresaId: tenantId,
      productId: Number(id),
      page: Number(page),
      perPage: Number(perPage),
    });
  }
}

