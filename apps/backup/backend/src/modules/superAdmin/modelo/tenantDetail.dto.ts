import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';

export class TenantDetailDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'Empresa Demo' })
  companyName!: string;

  @ApiProperty({ enum: ['active', 'suspended'] })
  status!: 'active' | 'suspended';

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  createdAt!: string;

  @ApiPropertyOptional({ example: 'localhost' })
  dbHost?: string;

  @ApiPropertyOptional({ example: 3306 })
  dbPort?: number;

  @ApiPropertyOptional({ example: 'core_tenant_1' })
  dbName?: string;

  @ApiPropertyOptional({ example: 'core_u_1' })
  dbUser?: string;

  @ApiPropertyOptional({ example: 1 })
  licenseCount?: number;

  @ApiPropertyOptional({ example: 'ABC-123', nullable: true })
  lastLicense?: string | null;
}

