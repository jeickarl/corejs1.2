import { Module } from '@nestjs/common';
import { SuppliersDao } from './daos/suppliers.dao';
import { SuppliersController } from './controller/suppliers.controller';
import { SuppliersEndpoint } from './endpoint/suppliers.endpoint';
import { PurchaseOrdersDao } from './daos/purchaseOrders.dao';
import { PurchaseOrdersController } from './controller/purchaseOrders.controller';
import { PurchaseOrdersEndpoint } from './endpoint/purchaseOrders.endpoint';
import { SupplierPaymentsDao } from './daos/supplierPayments.dao';
import { SupplierPaymentsController } from './controller/supplierPayments.controller';
import { SupplierPaymentsEndpoint } from './endpoint/supplierPayments.endpoint';
import { PurchaseReceiptsDao } from './daos/purchaseReceipts.dao';
import { PurchaseReceiptsController } from './controller/purchaseReceipts.controller';
import { PurchaseReceiptsEndpoint } from './endpoint/purchaseReceipts.endpoint';

@Module({
  controllers: [SuppliersEndpoint, PurchaseOrdersEndpoint, SupplierPaymentsEndpoint, PurchaseReceiptsEndpoint],
  providers: [
    SuppliersController,
    SuppliersDao,
    PurchaseOrdersController,
    PurchaseOrdersDao,
    SupplierPaymentsController,
    SupplierPaymentsDao,
    PurchaseReceiptsController,
    PurchaseReceiptsDao,
  ],
})
export class SuppliersModule {}
