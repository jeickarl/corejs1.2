import { Module } from '@nestjs/common';
import { ReportsDao } from './daos/reports.dao';
import { ReportsController } from './controller/reports.controller';
import { ReportsEndpoint } from './endpoint/reports.endpoint';

@Module({
  controllers: [ReportsEndpoint],
  providers: [ReportsDao, ReportsController],
})
export class ReportsModule {}

