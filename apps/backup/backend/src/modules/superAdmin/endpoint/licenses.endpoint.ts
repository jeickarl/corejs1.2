import { Body, Controller, Get, Param, Post, UseGuards } from '@nestjs/common';
import { ApiTags } from '@nestjs/swagger';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import { LicensesController } from '../controller/licenses.controller';
import { AssignLicenseDto } from '../modelo/assignLicense.dto';

@ApiTags('super-admin')
@Controller('super-admin/licenses')
@UseGuards(RolesGuard)
@Roles('SUPER_ADMIN')
export class LicensesEndpoint {
  constructor(private readonly licensesController: LicensesController) {}

  @Get('available')
  available() {
    return this.licensesController.available();
  }

  @Post('tenant/:id/generate-assign')
  generateAssign(@Param('id') id: string) {
    return this.licensesController.generateAndAssign(Number(id));
  }

  @Post('tenant/:id/assign')
  assign(@Param('id') id: string, @Body() body: AssignLicenseDto) {
    return this.licensesController.assign(Number(id), body.code);
  }
}

