import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsNumber, IsOptional, Min } from 'class-validator';

export class CloseCashDto {
  @ApiProperty({ example: 0 })
  @IsNumber()
  @Min(0)
  finalAmount!: number;

  @ApiPropertyOptional({ example: 0, nullable: true })
  @IsOptional()
  @IsNumber()
  @Min(0)
  physicalCount?: number | null;
}

