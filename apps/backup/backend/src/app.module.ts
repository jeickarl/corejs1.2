import { Module } from '@nestjs/common';
import { AuthModule } from './modules/auth/auth.module';
import { ClientsModule } from './modules/clients/clients.module';
import { CashModule } from './modules/cash/cash.module';
import { DashboardModule } from './modules/dashboard/dashboard.module';
import { HealthModule } from './modules/health/health.module';
import { OrdersModule } from './modules/orders/orders.module';
import { SalesModule } from './modules/sales/sales.module';
import { InventoryModule } from './modules/inventory/inventory.module';
import { SettingsModule } from './modules/settings/settings.module';
import { SuperAdminModule } from './modules/superAdmin/superAdmin.module';
import { SuppliersModule } from './modules/suppliers/suppliers.module';
import { ServicesModule } from './modules/services/services.module';
import { BackupModule } from './modules/backup/backup.module';
import { PortalModule } from './modules/portal/portal.module';
import { NotificationsModule } from './modules/notifications/notifications.module';
import { ReportsModule } from './modules/reports/reports.module';
import { DbModule } from './infrastructure/db/db.module';

@Module({
  imports: [
    DbModule,
    AuthModule,
    ClientsModule,
    DashboardModule,
    OrdersModule,
    InventoryModule,
    SettingsModule,
    SalesModule,
    CashModule,
    HealthModule,
    SuperAdminModule,
    SuppliersModule,
    ServicesModule,
    BackupModule,
    PortalModule,
    NotificationsModule,
    ReportsModule,
  ],
  controllers: [],
  providers: [],
})
export class AppModule {}
