import { Module } from '@nestjs/common';
import { CashController } from './controller/cash.controller';
import { CashDao } from './daos/cash.dao';
import { CashEndpoint } from './endpoint/cash.endpoint';

@Module({
  controllers: [CashEndpoint],
  providers: [CashController, CashDao],
  exports: [CashDao],
})
export class CashModule {}

