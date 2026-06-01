import { Module } from '@nestjs/common';
import { BackupController } from './controller/backup.controller';
import { BackupEndpoint } from './endpoint/backup.endpoint';
import { BackupDao } from './daos/backup.dao';

@Module({
  controllers: [BackupEndpoint],
  providers: [BackupController, BackupDao],
})
export class BackupModule {}
