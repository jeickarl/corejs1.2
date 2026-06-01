import { Module } from '@nestjs/common';
import { SalesController } from './controller/sales.controller';
import { InvoicesDao } from './daos/invoices.dao';
import { SalesEndpoint } from './endpoint/sales.endpoint';

@Module({
  controllers: [SalesEndpoint],
  providers: [SalesController, InvoicesDao],
})
export class SalesModule {}

