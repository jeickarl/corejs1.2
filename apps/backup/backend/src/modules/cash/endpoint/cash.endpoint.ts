import { Body, Controller, Get, Patch, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { CashController } from '../controller/cash.controller';
import { CashMovementDto } from '../modelo/cashMovement.dto';
import { CashSessionDto } from '../modelo/cashSession.dto';
import { CashSummaryDto } from '../modelo/cashSummary.dto';
import { CloseCashDto } from '../modelo/closeCash.dto';
import { OpenCashDto } from '../modelo/openCash.dto';

@ApiTags('cash')
@Controller('cash')
@UseGuards(RolesGuard)
@Roles('ADMIN', 'USER')
export class CashEndpoint {
  constructor(private readonly cashController: CashController) {}

  @Get('session')
  @ApiOkResponse({ type: CashSessionDto })
  session(@Req() req: Request & { user?: unknown }) {
    const user = req.user as AuthTokenPayload;
    if (!user.tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.cashController.me({ empresaId: user.tenantId });
  }

  @Post('open')
  open(@Req() req: Request & { user?: unknown }, @Body() body: OpenCashDto) {
    const user = req.user as AuthTokenPayload;
    if (!user.tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.cashController.open({
      empresaId: user.tenantId,
      openedBy: user.sub ?? null,
      initialAmount: body.initialAmount,
    });
  }

  @Patch('close')
  close(@Req() req: Request & { user?: unknown }, @Body() body: CloseCashDto) {
    const user = req.user as AuthTokenPayload;
    if (!user.tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.cashController.close({
      empresaId: user.tenantId,
      closedBy: user.sub ?? null,
      finalAmount: body.finalAmount,
      physicalCount: body.physicalCount ?? null,
    });
  }

  @Get('summary')
  @ApiOkResponse({ type: CashSummaryDto })
  summary(@Req() req: Request & { user?: unknown }, @Query('cashSessionId') cashSessionId = '') {
    const user = req.user as AuthTokenPayload;
    if (!user.tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.cashController.summary({ empresaId: user.tenantId, cashSessionId: Number(cashSessionId) });
  }

  @Get('movements')
  @ApiOkResponse({ type: [CashMovementDto] })
  movements(
    @Req() req: Request & { user?: unknown },
    @Query('cashSessionId') cashSessionId = '',
    @Query('limit') limit = '200',
  ) {
    const user = req.user as AuthTokenPayload;
    if (!user.tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.cashController.movements({
      empresaId: user.tenantId,
      cashSessionId: Number(cashSessionId),
      limit: Number(limit),
    });
  }
}

