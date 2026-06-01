import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsNumber, IsOptional, IsString, MinLength } from 'class-validator';

export class CreateSupplierPaymentDto {
  @ApiProperty({ example: 1 })
  @IsNumber()
  supplierId!: number;

  @ApiPropertyOptional({ example: 1 })
  @IsOptional()
  @IsNumber()
  purchaseOrderId?: number;

  @ApiProperty({ example: 1000 })
  @IsNumber()
  paymentAmount!: number;

  @ApiPropertyOptional({ example: 'Efectivo' })
  @IsOptional()
  @IsString()
  paymentMethod?: string;

  @ApiProperty({ example: '2026-01-01' })
  @IsString()
  @MinLength(1)
  paymentDate!: string;

  @ApiPropertyOptional({ example: 'REF-1' })
  @IsOptional()
  @IsString()
  referenceNumber?: string;

  @ApiPropertyOptional({ example: 'Notas' })
  @IsOptional()
  @IsString()
  notes?: string;

  @ApiPropertyOptional({ example: 'req-uuid' })
  @IsOptional()
  @IsString()
  requestId?: string;
}

