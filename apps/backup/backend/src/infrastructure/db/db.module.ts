import { Global, Module } from '@nestjs/common';
import { createMasterPool } from './master.pool';

@Global()
@Module({
  providers: [
    {
      provide: 'MASTER_DB_POOL',
      useFactory: () => createMasterPool(),
    },
  ],
  exports: ['MASTER_DB_POOL'],
})
export class DbModule {}
