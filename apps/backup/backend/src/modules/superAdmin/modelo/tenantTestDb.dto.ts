import { ApiPropertyOptional } from '@nestjs/swagger';
import { IsInt, IsOptional, IsString, Min, MinLength } from 'class-validator';

export class TenantTestDbDto {
  @ApiPropertyOptional({ example: 'localhost' })
  @IsOptional()
  @IsString()
  @MinLength(1)
  dbHost?: string;

  @ApiPropertyOptional({ example: 3306 })
  @IsOptional()
  @IsInt()
  @Min(1)
  dbPort?: number;

  @ApiPropertyOptional({ example: 'core_tenant_1' })
  @IsOptional()
  @IsString()
  @MinLength(1)
  dbName?: string;

  @ApiPropertyOptional({ example: 'core_u_1' })
  @IsOptional()
  @IsString()
  @MinLength(1)
  dbUser?: string;

  @ApiPropertyOptional({ example: 'password', nullable: true })
  @IsOptional()
  @IsString()
  dbPass?: string | null;
}

