import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsIn, IsInt, IsOptional, IsString, Min, MinLength } from 'class-validator';

export class TenantUpdateDto {
  @ApiProperty({ example: 'Empresa Demo' })
  @IsString()
  @MinLength(1)
  companyName!: string;

  @ApiProperty({ enum: ['active', 'suspended', 'provisioning'] })
  @IsIn(['active', 'suspended', 'provisioning'])
  status!: 'active' | 'suspended' | 'provisioning';

  @ApiProperty({ example: 'localhost' })
  @IsString()
  @MinLength(1)
  dbHost!: string;

  @ApiProperty({ example: 3306 })
  @IsInt()
  @Min(1)
  dbPort!: number;

  @ApiProperty({ example: 'core_tenant_1' })
  @IsString()
  @MinLength(1)
  dbName!: string;

  @ApiProperty({ example: 'core_u_1' })
  @IsString()
  @MinLength(1)
  dbUser!: string;

  @ApiPropertyOptional({ example: 'password', nullable: true })
  @IsOptional()
  @IsString()
  dbPass?: string | null;
}

