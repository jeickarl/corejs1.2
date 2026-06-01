import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';

export class InvoicePaymentDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 10000 })
  paymentAmount!: number;

  @ApiProperty({ example: 'Efectivo' })
  paymentMethod!: string;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  paymentDate!: string;

  @ApiPropertyOptional({ example: 'REF123', nullable: true })
  referenceNumber!: string | null;

  @ApiPropertyOptional({ example: '', nullable: true })
  notes!: string | null;
}

