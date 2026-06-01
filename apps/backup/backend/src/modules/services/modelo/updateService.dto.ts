import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsBoolean, IsInt, IsNumber, IsOptional, IsString, Min, MinLength } from 'class-validator';

export class UpdateServiceDto {
  @ApiProperty({ example: 'Cambio de pantalla' })
  @IsString()
  @MinLength(1)
  name!: string;

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  description?: string;

  @ApiProperty({ example: 1 })
  @IsInt()
  @Min(1)
  deviceCategoryId!: number;

  @ApiPropertyOptional({ example: 0 })
  @IsOptional()
  @IsNumber()
  @Min(0)
  basePrice?: number;

  @ApiPropertyOptional({ example: 0 })
  @IsOptional()
  @IsInt()
  @Min(0)
  estimatedTime?: number;

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  notes?: string;

  @ApiProperty({ example: true })
  @IsBoolean()
  active!: boolean;
}

