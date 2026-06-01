import { Module } from '@nestjs/common';
import { InventoryDao } from './daos/inventory.dao';
import { InventoryController } from './controller/inventory.controller';
import { InventoryEndpoint } from './endpoint/inventory.endpoint';

@Module({
  controllers: [InventoryEndpoint],
  providers: [InventoryController, InventoryDao],
})
export class InventoryModule {}

