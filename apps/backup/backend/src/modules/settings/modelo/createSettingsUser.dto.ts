import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsBoolean, IsIn, IsOptional, IsString, MinLength } from 'class-validator';

export class CreateSettingsUserDto {
  @ApiProperty({ example: 'user@empresa.com' })
  @IsString()
  @MinLength(3)
  email!: string;

  @ApiProperty({ example: 'Juan Pérez' })
  @IsString()
  @MinLength(1)
  name!: string;

  @ApiProperty({ enum: ['admin', 'user'] })
  @IsIn(['admin', 'user'])
  role!: 'admin' | 'user';

  @ApiProperty({ example: '123456' })
  @IsString()
  @MinLength(4)
  password!: string;

  @ApiPropertyOptional({ example: true })
  @IsOptional()
  @IsBoolean()
  active?: boolean;
}

