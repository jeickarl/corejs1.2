import { ApiProperty } from '@nestjs/swagger';
import { IsInt, IsString, Min, MinLength } from 'class-validator';

export class DbPoolAddDto {
  @ApiProperty({ example: 'localhost' })
  @IsString()
  @MinLength(1)
  dbHost!: string;

  @ApiProperty({ example: 3306 })
  @IsInt()
  @Min(1)
  dbPort!: number;

  @ApiProperty({ example: 'core_tenant_000123' })
  @IsString()
  @MinLength(1)
  dbName!: string;

  @ApiProperty({ example: 'core_u_000123' })
  @IsString()
  @MinLength(1)
  dbUser!: string;

  @ApiProperty({ example: 'password' })
  @IsString()
  @MinLength(1)
  dbPass!: string;
}

