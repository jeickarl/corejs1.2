import { Body, Controller, Get, Param, Post, Query } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import { PortalController } from '../controller/portal.controller';
import { PortalVerifyDto } from '../modelo/portalVerify.dto';
import { PortalSubmitApprovalDto } from '../modelo/portalSubmitApproval.dto';

@ApiTags('portal')
@Controller('portal')
export class PortalEndpoint {
  constructor(private readonly portalController: PortalController) {}

  @Get(':tenantId/config')
  @ApiOkResponse({ type: Object })
  config(@Param('tenantId') tenantId: string) {
    const empresaId = Number(tenantId);
    return this.portalController.config({ empresaId });
  }

  @Post(':tenantId/verify')
  @ApiOkResponse({ type: Object })
  verify(@Param('tenantId') tenantId: string, @Body() body: PortalVerifyDto) {
    const empresaId = Number(tenantId);
    return this.portalController.verify({ empresaId, mode: body.mode, query: body.query });
  }

  @Get(':tenantId/receipt')
  @ApiOkResponse({ type: Object })
  receipt(@Param('tenantId') tenantId: string, @Query('orderId') orderId = '') {
    const empresaId = Number(tenantId);
    return this.portalController.receipt({ empresaId, orderId: Number(orderId) });
  }

  @Post(':tenantId/orders/:orderId/approval')
  submitApproval(@Param('tenantId') tenantId: string, @Param('orderId') orderId: string, @Body() body: PortalSubmitApprovalDto) {
    const empresaId = Number(tenantId);
    return this.portalController.submitApproval({
      empresaId,
      orderId: Number(orderId),
      verificationCode: body.verificationCode,
      decision: body.decision,
      comment: body.comment,
    });
  }
}

