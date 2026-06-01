import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsBoolean, IsOptional, IsString, MinLength } from 'class-validator';

export class CreateDeviceTypeDto {
  @ApiProperty({ example: 'Celular' })
  @IsString()
  @MinLength(1)
  name!: string;

  @ApiPropertyOptional({ example: 0 })
  @IsOptional()
  sortOrder?: number;

  @ApiPropertyOptional({ example: true })
  @IsOptional()
  @IsBoolean()
  isActive?: boolean;
}

