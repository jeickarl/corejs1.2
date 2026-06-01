import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsArray, IsIn, IsInt, IsOptional, IsString, Min } from 'class-validator';
import { CreateInvoiceItemDto } from './createInvoiceItem.dto';
import { CreateInvoicePaymentDto } from './createInvoicePayment.dto';

export class CreateInvoiceDto {
  @ApiProperty({ example: 1 })
  @IsInt()
  @Min(1)
  clientId!: number;

  @ApiPropertyOptional({ example: 'Factura' })
  @IsOptional()
  @IsString()
  documentType?: string;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  @IsString()
  invoiceDate!: string;

  @ApiPropertyOptional({ example: '2026-01-15', nullable: true })
  @IsOptional()
  @IsString()
  dueDate?: string | null;

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  notes?: string;

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  termsConditions?: string;

  @ApiProperty({ enum: ['save', 'save_pending'] })
  @IsIn(['save', 'save_pending'])
  action!: 'save' | 'save_pending';

  @ApiProperty({ type: [CreateInvoiceItemDto] })
  @IsArray()
  items!: CreateInvoiceItemDto[];

  @ApiPropertyOptional({ type: [CreateInvoicePaymentDto] })
  @IsOptional()
  @IsArray()
  payments?: CreateInvoicePaymentDto[];
}

