import { Body, Controller, Get, Param, Patch, Post, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import { DbPoolController } from '../controller/dbPool.controller';
import { DbPoolAddDto } from '../modelo/dbPoolAdd.dto';
import { DbPoolListDto } from '../modelo/dbPool.dto';

@ApiTags('super-admin')
@Controller('super-admin/db-pool')
@UseGuards(RolesGuard)
@Roles('SUPER_ADMIN')
export class DbPoolEndpoint {
  constructor(private readonly dbPoolController: DbPoolController) {}

  @Get()
  @ApiOkResponse({ type: DbPoolListDto })
  list() {
    return this.dbPoolController.list();
  }

  @Post()
  add(@Body() body: DbPoolAddDto) {
    return this.dbPoolController.add({
      dbHost: body.dbHost,
      dbPort: body.dbPort,
      dbName: body.dbName,
      dbUser: body.dbUser,
      dbPass: body.dbPass,
    });
  }

  @Post('sync-from-empresas')
  syncFromEmpresas() {
    return this.dbPoolController.syncFromEmpresas();
  }

  @Patch(':id/mark-available')
  markAvailable(@Param('id') id: string) {
    return this.dbPoolController.markAvailable(Number(id));
  }
}

