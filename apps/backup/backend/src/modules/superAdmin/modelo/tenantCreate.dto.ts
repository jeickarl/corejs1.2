import { ApiProperty } from '@nestjs/swagger';
import { IsEmail, IsInt, IsString, Min, MinLength } from 'class-validator';

export class TenantCreateDto {
  @ApiProperty({ example: 'Empresa Demo' })
  @IsString()
  @MinLength(1)
  companyName!: string;

  @ApiProperty({ example: 'localhost' })
  @IsString()
  @MinLength(1)
  dbHost!: string;

  @ApiProperty({ example: 3306 })
  @IsInt()
  @Min(1)
  dbPort!: number;

  @ApiProperty({ example: 'core_tenant_000001' })
  @IsString()
  @MinLength(1)
  dbName!: string;

  @ApiProperty({ example: 'core_u_000001' })
  @IsString()
  @MinLength(1)
  dbUser!: string;

  @ApiProperty({ example: 'password' })
  @IsString()
  @MinLength(1)
  dbPass!: string;

  @ApiProperty({ example: 'Admin' })
  @IsString()
  @MinLength(1)
  adminName!: string;

  @ApiProperty({ example: 'admin@empresa.com' })
  @IsEmail()
  adminEmail!: string;

  @ApiProperty({ example: 'Admin2026!' })
  @IsString()
  @MinLength(6)
  adminPassword!: string;
}

