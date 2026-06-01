import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsNumber, IsOptional, IsString, Min } from 'class-validator';

export class CreateInvoicePaymentDto {
  @ApiProperty({ example: 10000 })
  @IsNumber()
  @Min(0.01)
  paymentAmount!: number;

  @ApiProperty({ example: 'Efectivo' })
  @IsString()
  paymentMethod!: string;

  @ApiPropertyOptional({ example: '2026-01-01 10:00:00' })
  @IsOptional()
  @IsString()
  paymentDate?: string;

  @ApiPropertyOptional({ example: 'REF123' })
  @IsOptional()
  @IsString()
  referenceNumber?: string;

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  notes?: string;
}

