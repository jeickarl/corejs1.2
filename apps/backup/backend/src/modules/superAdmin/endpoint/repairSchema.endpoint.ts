import { Controller, Param, Post, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import { RepairSchemaController } from '../controller/repairSchema.controller';
import { RepairSchemaTenantResultDto } from '../modelo/repairSchemaResult.dto';

@ApiTags('super-admin')
@Controller('super-admin/repair-schema')
@UseGuards(RolesGuard)
@Roles('SUPER_ADMIN')
export class RepairSchemaEndpoint {
  constructor(private readonly repairSchemaController: RepairSchemaController) {}

  @Post()
  @ApiOkResponse({ type: [RepairSchemaTenantResultDto] })
  repairAll() {
    return this.repairSchemaController.repairAll();
  }

  @Post('tenant/:id')
  @ApiOkResponse({ type: RepairSchemaTenantResultDto })
  repairTenant(@Param('id') id: string) {
    return this.repairSchemaController.repairTenant(Number(id));
  }
}

