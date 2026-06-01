import { ApiProperty } from '@nestjs/swagger';

export class InvoiceListItemDto {
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
}

