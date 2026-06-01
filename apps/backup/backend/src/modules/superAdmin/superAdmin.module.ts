import { Module } from '@nestjs/common';
import { DbPoolController } from './controller/dbPool.controller';
import { HealthController } from './controller/health.controller';
import { LicensesController } from './controller/licenses.controller';
import { RepairSchemaController } from './controller/repairSchema.controller';
import { TenantsController } from './controller/tenants.controller';
import { DbPoolEndpoint } from './endpoint/dbPool.endpoint';
import { HealthEndpoint } from './endpoint/health.endpoint';
import { LicensesEndpoint } from './endpoint/licenses.endpoint';
import { RepairSchemaEndpoint } from './endpoint/repairSchema.endpoint';
import { TenantsEndpoint } from './endpoint/tenants.endpoint';
import { DbPoolDao } from './daos/dbPool.dao';
import { HealthDao } from './daos/health.dao';
import { LicensesDao } from './daos/licenses.dao';
import { RepairSchemaDao } from './daos/repairSchema.dao';
import { TenantsDao } from './daos/tenants.dao';
import { MasterTenantUsersDao } from './daos/masterTenantUsers.dao';

@Module({
  controllers: [DbPoolEndpoint, HealthEndpoint, LicensesEndpoint, RepairSchemaEndpoint, TenantsEndpoint],
  providers: [
    DbPoolController,
    HealthController,
    LicensesController,
    RepairSchemaController,
    TenantsController,
    DbPoolDao,
    HealthDao,
    LicensesDao,
    RepairSchemaDao,
    TenantsDao,
    MasterTenantUsersDao,
  ],
})
export class SuperAdminModule {}
