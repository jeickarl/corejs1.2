import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { InvoiceItemDto } from './invoiceItem.dto';
import { InvoicePaymentDto } from './invoicePayment.dto';

export class InvoiceDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'FAC-00001' })
  invoiceNumber!: string;

  @ApiProperty({ example: 1 })
  clientId!: number;

  @ApiProperty({ example: 'Cliente' })
  clientName!: string;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  invoiceDate!: string;

  @ApiPropertyOptional({ example: '2026-01-15', nullable: true })
  dueDate!: string | null;

  @ApiPropertyOptional({ example: 'Factura', nullable: true })
  documentType!: string | null;

  @ApiProperty({ example: 10000 })
  subtotal!: number;

  @ApiProperty({ example: 0 })
  discountAmount!: number;

  @ApiProperty({ example: 0 })
  taxAmount!: number;

  @ApiProperty({ example: 10000 })
  totalAmount!: number;

  @ApiProperty({ example: 0 })
  paidAmount!: number;

  @ApiProperty({ example: 10000 })
  pendingAmount!: number;

  @ApiProperty({ enum: ['pending', 'partial', 'paid'] })
  paymentStatus!: 'pending' | 'partial' | 'paid';

  @ApiProperty({ enum: ['draft', 'sent', 'paid', 'cancelled'] })
  status!: 'draft' | 'sent' | 'paid' | 'cancelled';

  @ApiPropertyOptional({ example: '', nullable: true })
  notes!: string | null;

  @ApiPropertyOptional({ example: '', nullable: true })
  termsConditions!: string | null;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  createdAt!: string;

  @ApiPropertyOptional({ example: '2026-01-01 10:00:00', nullable: true })
  cancelledAt!: string | null;

  @ApiPropertyOptional({ example: '', nullable: true })
  cancellationReason!: string | null;

  @ApiProperty({ type: [InvoiceItemDto] })
  items!: InvoiceItemDto[];

  @ApiProperty({ type: [InvoicePaymentDto] })
  payments!: InvoicePaymentDto[];
}

