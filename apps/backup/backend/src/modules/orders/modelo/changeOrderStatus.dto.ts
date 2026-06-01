import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsNumber, IsOptional, IsString, MinLength } from 'class-validator';

export class ChangeOrderStatusDto {
  @ApiProperty({ example: 'completed' })
  @IsString()
  @MinLength(1)
  status!: string;

  @ApiPropertyOptional({ example: 0 })
  @IsOptional()
  @IsNumber()
  finalCost?: number;
}

