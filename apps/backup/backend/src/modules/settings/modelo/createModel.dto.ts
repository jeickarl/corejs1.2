import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsBoolean, IsOptional, IsString, MinLength } from 'class-validator';

export class CreateModelDto {
  @ApiProperty({ example: 'A51' })
  @IsString()
  @MinLength(1)
  name!: string;

  @ApiPropertyOptional({ example: 1, nullable: true })
  @IsOptional()
  brandId?: number | null;

  @ApiPropertyOptional({ example: 1, nullable: true })
  @IsOptional()
  deviceTypeId?: number | null;

  @ApiPropertyOptional({ example: true })
  @IsOptional()
  @IsBoolean()
  isActive?: boolean;
}
