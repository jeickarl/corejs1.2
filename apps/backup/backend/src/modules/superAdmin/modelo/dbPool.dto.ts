import { ApiProperty } from '@nestjs/swagger';

export class DbPoolStatsDto {
  @ApiProperty()
  available!: number;

  @ApiProperty()
  reserved!: number;

  @ApiProperty()
  used!: number;

  @ApiProperty()
  error!: number;
}

export class DbPoolItemDto {
  @ApiProperty()
  id!: number;

  @ApiProperty()
  dbHost!: string;

  @ApiProperty()
  dbPort!: number;

  @ApiProperty()
  dbName!: string;

  @ApiProperty()
  dbUser!: string;

  @ApiProperty({ enum: ['available', 'reserved', 'used', 'error'] })
  status!: 'available' | 'reserved' | 'used' | 'error';

  @ApiProperty({ nullable: true })
  empresaId!: number | null;

  @ApiProperty({ nullable: true })
  empresaNombre!: string | null;

  @ApiProperty({ nullable: true })
  reservedAt!: string | null;

  @ApiProperty({ nullable: true })
  usedAt!: string | null;

  @ApiProperty()
  createdAt!: string;

  @ApiProperty({ nullable: true })
  lastError!: string | null;
}

export class DbPoolListDto {
  @ApiProperty({ type: DbPoolStatsDto })
  stats!: DbPoolStatsDto;

  @ApiProperty({ type: [DbPoolItemDto] })
  items!: DbPoolItemDto[];
}

