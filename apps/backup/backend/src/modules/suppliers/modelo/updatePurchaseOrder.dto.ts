import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsOptional, IsString, MinLength } from 'class-validator';

export class UpdatePurchaseOrderDto {
  @ApiProperty({ example: 1 })
  supplierId!: number;

  @ApiProperty({ example: '2026-01-01' })
  @IsString()
  @MinLength(1)
  orderDate!: string;

  @ApiPropertyOptional({ example: '2026-01-10' })
  @IsOptional()
  @IsString()
  expectedDate?: string;

  @ApiPropertyOptional({ example: 'Efectivo' })
  @IsOptional()
  @IsString()
  paymentMethod?: string;

  @ApiPropertyOptional({ example: '30_days' })
  @IsOptional()
  @IsString()
  paymentTerms?: string;

  @ApiPropertyOptional({ example: 'Notas' })
  @IsOptional()
  @IsString()
  notes?: string;

  @ApiPropertyOptional({ example: 'draft' })
  @IsOptional()
  @IsString()
  status?: string;
}

